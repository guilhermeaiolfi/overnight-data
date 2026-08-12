<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation;

use ON\Data\Database\QueryExecutorInterface;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\ORM\Representation\Schema\RepresentationSchema;
use ON\Data\Query\Exception\LoadRuntimeException;
use ON\Data\Query\Exception\RelationSelectionException;
use ON\Data\Query\QuerySourceInterface;
use ON\Data\Query\Result\Parser\AbstractNode;
use ON\Data\Query\Selection\SelectionItem;
use ON\Data\Query\Selection\SelectionTag;
use ON\Data\Query\SelectQuery;
use ReflectionMethod;

final class LoadRuntime
{
	/**
	 * @var array<string, RelationLoadBranch>
	 */
	private array $branches = [];

	private ?string $activeMethod = null;

	private bool $registering = false;

	private bool $continuationRequested = false;

	private ?SelectQuery $currentContinuationQuery = null;

	/**
	 * @var array<int, SelectQuery>
	 */
	private array $pendingContinuationQueries = [];

	private int $loaderInvocationDepth = 0;

	private bool $flushingPendingContinuations = false;

	private RootLoadBranch $rootBranch;

	private RelationOutputProcessor $outputProcessor;

	private LoadAliasAllocator $aliases;

	private LoadFieldPlanner $fieldPlanner;

	public function __construct(
		private readonly SelectQuery $rootQuery,
		private readonly QueryExecutorInterface $executor,
		?RepresentationSchema $schema = null,
	) {
		$this->rootBranch = new RootLoadBranch($rootQuery);
		$this->outputProcessor = new RelationOutputProcessor($schema);
		$this->aliases = new LoadAliasAllocator();
		$this->fieldPlanner = new LoadFieldPlanner($this, $this->aliases);
	}

	public function fetchAll(): array
	{
		// Plain own-field reads: executor already returns place keys.
		// Renames, flats, and INTERNAL/SQL_ONLY remaps need assemble.
		if (! $this->rootQuery->needsRowAssemble()) {
			return $this->executor->fetchAll($this->rootQuery);
		}

		$this->prepare();
		$this->rootBranch->parseRows($this->executor->fetchAll($this->rootQuery));
		$this->runContinuationsFor($this->rootQuery);

		return $this->outputProcessor->processRoot($this->rootBranch);
	}

	public function fetchOne(): ?array
	{
		if (! $this->rootQuery->needsRowAssemble()) {
			return $this->executor->fetchOne($this->rootQuery);
		}

		$this->prepare();
		$row = $this->executor->fetchOne($this->rootQuery);

		if ($row === null) {
			return null;
		}

		$this->rootBranch->parseRows([$row]);
		$this->runContinuationsFor($this->rootQuery);

		return $this->outputProcessor->processRoot($this->rootBranch)[0] ?? null;
	}

	/**
	 * @return iterable<array<string, mixed>>
	 */
	public function iterate(): iterable
	{
		if (! $this->rootQuery->getRelationSelections()->isEmpty()) {
			throw RelationSelectionException::iterateNotSupported();
		}

		return $this->executor->iterate($this->rootQuery);
	}

	public function getQueryRelation(RelationLoadBranch $branch): RelationRef
	{
		$parent = $branch->getParent();
		$query = $parent->getQuery();

		if ($parent->getQueryLocalRelation() === null) {
			return $query->relation($branch->getRelationRef()->getName());
		}

		return $parent->getQueryLocalRelation()
			->relation($branch->getRelationRef()->getName());
	}

	public function createQuery(CollectionInterface $collection): SelectQuery
	{
		return $this->rootQuery->related($collection);
	}

	public function setQueryContext(
		RelationLoadBranch $branch,
		SelectQuery $query,
		QuerySourceInterface $source,
		?RelationRef $queryLocalRelation = null,
	): void {
		$branch->setQueryContext($query, $source, $queryLocalRelation);
	}

	public function continueWith(RelationLoadBranch $branch, string $method = 'load'): void
	{
		if ($this->registering) {
			throw LoadRuntimeException::continuationNotAllowedDuringRegister($branch->getRelationRef());
		}

		if ($this->continuationRequested) {
			throw LoadRuntimeException::multipleContinuations($branch->getRelationRef(), $this->activeMethod ?? 'load');
		}

		$this->assertContinuableMethod($branch, $method);
		$branch->setContinuation(
			$method,
			$this->currentContinuationQuery ?? throw LoadRuntimeException::continuationQueryMissing($branch->getRelationRef()),
		);
		$this->continuationRequested = true;
	}

	public function execute(RelationLoadBranch $branch, SelectQuery $query): void
	{
		$rows = $this->executor->fetchAll($query);

		foreach ($rows as $row) {
			$branch->getNode()->parseRow(
				0,
				LoadBranch::orderedValues($row, $branch->getNode()->getValueAliasTraversal()),
			);
		}

		$continuationQuery = $branch->getQuery();
		$this->currentContinuationQuery = $continuationQuery;
		$this->pendingContinuationQueries[spl_object_id($continuationQuery)] = $continuationQuery;
	}

	public function getLoadStrategy(RelationLoadBranch $branch): LoadStrategy
	{
		return $branch->getSelection()->getStrategy()
			?? $branch->getLoader()->getDefaultLoadStrategy();
	}

	/**
	 * Register required fields and, when query context exists, emit SQL columns now.
	 *
	 * Root and nested destinations share this path: the branch records place keys,
	 * then the planner emits load-local SQL and binds place→load.
	 *
	 * @param list<string> $fieldNames
	 * @return list<string> load-local parser keys
	 */
	public function requireFields(LoadBranch $branch, array $fieldNames): array
	{
		$placeKeys = $branch->requireFields($fieldNames);

		if (! $branch->hasQueryContext() || $fieldNames === []) {
			return array_map(
				static fn (string $placeKey): string => $branch->loadKeyForPlace($placeKey),
				$placeKeys,
			);
		}

		$level = $branch->getProjectionLevel();
		$loadKeys = [];

		foreach ($placeKeys as $index => $placeKey) {
			if ($branch->hasPlaceBinding($placeKey) && $branch->childPathForPlace($placeKey) === null) {
				$loadKeys[] = $branch->loadKeyForPlace($placeKey);

				continue;
			}

			$fieldName = $fieldNames[$index] ?? $placeKey;
			$normalized = $branch->getCollection()->getField($fieldName)->getName();
			$fieldRef = $level instanceof RelationRef
				? $level->field($normalized)
				: $branch->getQuery()->field($normalized);
			$path = $level instanceof RelationRef ? $level->getPath() : [];
			$sqlOnly = $this->isRequiredOnly($branch, $placeKey);
			$preferred = $this->preferredLoadKey($branch, $placeKey, $normalized, $path, $sqlOnly);
			$sqlKey = $this->fieldPlanner->selectField($branch, $fieldRef, $placeKey, $preferred, $path, $sqlOnly);
			$branch->bindPlaceToLoadKey($placeKey, $sqlKey);
			$loadKeys[] = $sqlKey;
		}

		return $loadKeys;
	}

	private function isRequiredOnly(LoadBranch $branch, string $placeKey): bool
	{
		$selection = $branch->getSelections()->findBySelectionKey($placeKey);

		return ! $selection instanceof SelectionItem
			|| $selection->hasTag(SelectionTag::INTERNAL)
			|| ! $selection->isExplicit();
	}

	/**
	 * @param list<string> $path
	 */
	private function preferredLoadKey(
		LoadBranch $branch,
		string $placeKey,
		string $normalized,
		array $path,
		bool $sqlOnly,
	): string {
		$preferred = $branch->loadKeyForPlace($placeKey);

		if ($preferred !== $placeKey) {
			return $preferred;
		}

		if ($sqlOnly && $branch instanceof RootLoadBranch) {
			return $this->aliases->aliasForPath(['root', 'required'], $normalized);
		}

		$ownsQuery = $branch->getSource() === $branch->getQuery();

		return $ownsQuery
			? $normalized
			: $this->aliases->aliasForPath($path, $normalized);
	}

	private function prepare(): void
	{
		if ($this->rootBranch->hasNode()) {
			return;
		}

		$this->rootBranch->registerPublicSelections();
		$this->rootBranch->requirePrimaryKey();
		$this->requireFields($this->rootBranch, $this->rootBranch->getCollection()->getPrimaryKey());
		$this->createBranches();
		$this->configureBranches();
		$this->bindDestinations();
		$this->createParserTree();
	}

	private function bindDestinations(): void
	{
		$this->fieldPlanner->bindBranch($this->rootBranch);

		foreach ($this->relationBranchesByDepth() as $branch) {
			$this->fieldPlanner->bindBranch($branch);
		}
	}

	private function createBranches(): void
	{
		foreach ($this->rootQuery->getRelationSelections()->getAll() as $selection) {
			$key = RelationSelection::pathKey($selection->getPath());
			$parent = $selection->getParentPathKey() === null
				? $this->rootBranch
				: $this->branches[$selection->getParentPathKey()] ?? throw LoadRuntimeException::parentBranchMissing($selection->getRelationRef());

			$this->branches[$key] = new RelationLoadBranch($selection, $parent, $selection->getRelationRef()->getLoader());
		}
	}

	private function configureBranches(): void
	{
		foreach ($this->relationBranchesByDepth() as $branch) {
			$query = $branch->getParent()->getQuery();
			$this->invokeLoaderMethod($branch, 'load', $query);

			if ($branch->getQuery()->getCollection()->getName() === '') {
				throw LoadRuntimeException::queryNotConfigured($branch->getRelationRef());
			}
		}
	}

	/**
	 * @return list<RelationLoadBranch>
	 */
	private function relationBranchesByDepth(): array
	{
		$branches = array_values($this->branches);
		usort(
			$branches,
			static fn (RelationLoadBranch $left, RelationLoadBranch $right): int => count($left->getRelationRef()->getPath()) <=> count($right->getRelationRef()->getPath()),
		);

		return $branches;
	}

	private function createParserTree(): void
	{
		$rootNode = $this->rootBranch->createNode();

		foreach ($this->rootBranch->getChildren() as $branch) {
			$this->registerBranch($branch);
		}

		foreach ($this->rootBranch->getChildren() as $branch) {
			$node = $branch->getNode();

			if ($branch->isJoinedAttachment()) {
				$rootNode->joinNode($branch->getRelationRef()->getName(), $node);

				continue;
			}

			$rootNode->linkNode($branch->getRelationRef()->getName(), $node);
		}
	}

	public function registerBranch(RelationLoadBranch $branch): AbstractNode
	{
		$previousMethod = $this->activeMethod;
		$previousRegistering = $this->registering;
		$this->activeMethod = 'register';
		$this->registering = true;

		try {
			$node = $branch->getLoader()->register($branch, $this);
		} finally {
			$this->registering = $previousRegistering;
			$this->activeMethod = $previousMethod;
		}

		if (! $node instanceof AbstractNode) {
			throw LoadRuntimeException::nodeNotRegistered($branch->getRelationRef());
		}

		$branch->setNode($node);

		return $node;
	}

	private function invokeLoaderMethod(RelationLoadBranch $branch, string $method, SelectQuery $query): void
	{
		$loader = $branch->getLoader();
		$previousMethod = $this->activeMethod;
		$previousContinuationRequested = $this->continuationRequested;
		$previousCurrentContinuationQuery = $this->currentContinuationQuery;
		$this->activeMethod = $method;
		$this->continuationRequested = false;
		$this->currentContinuationQuery = $query;
		$this->loaderInvocationDepth++;
		$branch->clearContinuation();

		try {
			$loader->{$method}($branch, $this);
		} finally {
			$this->loaderInvocationDepth--;
			$this->activeMethod = $previousMethod;
			$this->continuationRequested = $previousContinuationRequested;
			$this->currentContinuationQuery = $previousCurrentContinuationQuery;
		}

		if ($this->loaderInvocationDepth === 0) {
			$this->runPendingContinuations();
		}
	}

	private function runContinuationsFor(SelectQuery $query): void
	{
		do {
			$ran = false;

			foreach ($this->branches as $branch) {
				if ($branch->getContinuationQuery() !== $query) {
					continue;
				}

				$method = $branch->getContinuationMethod();

				if ($method === null) {
					continue;
				}

				$branch->clearContinuation();
				$this->invokeLoaderMethod($branch, $method, $query);
				$ran = true;
			}
		} while ($ran);
	}

	private function runPendingContinuations(): void
	{
		if ($this->flushingPendingContinuations) {
			return;
		}

		$this->flushingPendingContinuations = true;

		try {
			while ($this->pendingContinuationQueries !== []) {
				$key = array_key_first($this->pendingContinuationQueries);

				if ($key === null) {
					break;
				}

				$query = $this->pendingContinuationQueries[$key];
				unset($this->pendingContinuationQueries[$key]);
				$this->runContinuationsFor($query);
			}
		} finally {
			$this->flushingPendingContinuations = false;
		}
	}

	/**
	 * Column alias for fields mounted onto a shared JOIN query.
	 * Unique per relation path + field; callers reuse the same name on re-require.
	 *
	 * @param list<string> $path
	 */
	public function getJoinedAlias(array $path, string $fieldName): string
	{
		return $this->aliases->aliasForPath($path, $fieldName);
	}

	private function assertContinuableMethod(RelationLoadBranch $branch, string $method): void
	{
		$loader = $branch->getLoader();

		if ($method === 'register' || $method === 'join') {
			throw LoadRuntimeException::invalidContinuationMethod($branch->getRelationRef(), $method);
		}

		if (! method_exists($loader, $method)) {
			throw LoadRuntimeException::invalidContinuationMethod($branch->getRelationRef(), $method);
		}

		$reflection = new ReflectionMethod($loader, $method);

		if (! $reflection->isPublic() || $reflection->getNumberOfParameters() !== 2) {
			throw LoadRuntimeException::invalidContinuationMethod($branch->getRelationRef(), $method);
		}
	}
}
