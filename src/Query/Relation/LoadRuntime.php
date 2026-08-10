<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation;

use ON\Data\Database\QueryExecutorInterface;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\ORM\Representation\Schema\RepresentationSchema;
use ON\Data\Query\Exception\LoadRuntimeException;
use ON\Data\Query\Exception\RelationSelectionException;
use ON\Data\Query\Expression\FieldRef;
use ON\Data\Query\QuerySourceInterface;
use ON\Data\Query\Result\Parser\AbstractNode;
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

	private LoadFieldPlanner $fieldPlanner;

	public function __construct(
		private readonly SelectQuery $rootQuery,
		private readonly QueryExecutorInterface $executor,
		?RepresentationSchema $schema = null,
	) {
		$this->rootBranch = new RootLoadBranch(
			$rootQuery,
			fn (string $fieldName): string => $this->getJoinedAlias(['root', 'required'], $fieldName),
		);
		$this->outputProcessor = new RelationOutputProcessor($schema);
		$this->fieldPlanner = new LoadFieldPlanner($this);
	}

	public function fetchAll(): array
	{
		// Plain own-field reads: executor already returns place keys.
		// Renames, flats, and INTERNAL/SQL_ONLY remaps need assemble.
		if (! $this->needsAssemble()) {
			return $this->executor->fetchAll($this->rootQuery);
		}

		$this->prepare();
		$this->rootBranch->parseRows($this->executor->fetchAll($this->rootQuery));
		$this->runContinuationsFor($this->rootQuery);

		return $this->outputProcessor->processRoot($this->rootBranch);
	}

	public function fetchOne(): ?array
	{
		if (! $this->needsAssemble()) {
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
	 * @param list<string> $fieldNames
	 * @return list<string> load-local parser keys
	 */
	public function requireFields(LoadBranch $branch, array $fieldNames): array
	{
		if ($branch instanceof RootLoadBranch) {
			// Root already emits SQL and returns load-local keys.
			return $branch->requireFields($fieldNames);
		}

		$placeKeys = $branch->requireFields($fieldNames);

		if (! $branch->hasQueryContext() || $fieldNames === []) {
			return array_map(
				static fn (string $placeKey): string => $branch->loadKeyForPlace($placeKey),
				$placeKeys,
			);
		}

		$level = $branch->getProjectionLevel();
		$ownsQuery = $branch->getSource() === $branch->getQuery();
		$loadKeys = [];

		foreach ($placeKeys as $index => $placeKey) {
			$fieldName = $fieldNames[$index] ?? $placeKey;
			$normalized = $branch->getCollection()->getField($fieldName)->getName();
			$fieldRef = $level instanceof RelationRef
				? $level->field($normalized)
				: $branch->getQuery()->field($normalized);
			$path = $level instanceof RelationRef ? $level->getPath() : [];
			$preferred = $branch->loadKeyForPlace($placeKey);
			if ($preferred === $placeKey) {
				// SEPARATE/root-owned queries can use the field name; JOIN onto a
				// shared parent query needs a path-stable alias (parent may already
				// expose the same field name).
				$preferred = $ownsQuery
					? $normalized
					: $this->getJoinedAlias($path, $normalized);
			}
			$sqlKey = $this->fieldPlanner->selectField($branch, $fieldRef, $placeKey, $preferred, $path);
			$branch->bindPlaceToLoadKey($placeKey, $sqlKey);
			$loadKeys[] = $sqlKey;
		}

		return $loadKeys;
	}

	private function prepare(): void
	{
		if ($this->rootBranch->hasNode()) {
			return;
		}

		$this->rootBranch->registerPublicSelections();
		$this->rootBranch->requirePrimaryKey();
		// Own-level root columns before relation load (loaders may inspect the root query).
		$this->bindAllDestinations(includeCrossLevelFlats: false);
		$this->createBranches();
		$this->configureBranches();

		// Flats after destinations exist so loaded to-one children can be reused.
		$this->bindAllDestinations(includeCrossLevelFlats: true);

		$this->createParserTree();

		$this->bindAllDestinations(includeCrossLevelFlats: true);
	}

	private function bindAllDestinations(bool $includeCrossLevelFlats): void
	{
		$this->fieldPlanner->bindBranch($this->rootBranch, $includeCrossLevelFlats);

		foreach ($this->relationBranchesByDepth() as $branch) {
			$this->fieldPlanner->bindBranch($branch, $includeCrossLevelFlats);
		}
	}

	/**
	 * Assemble when relation loads remapping, place≠load keys, or flat FieldRefs.
	 */
	private function needsAssemble(): bool
	{
		if (! $this->rootQuery->getRelationSelections()->isEmpty()) {
			return true;
		}

		foreach ($this->rootQuery->getSelections()->getAll() as $selection) {
			if (
				$selection->hasTag(SelectionTag::INTERNAL)
				|| $selection->hasTag(SelectionTag::SQL_ONLY)
			) {
				continue;
			}

			$fieldRef = $selection->getFieldRef();

			if (! $fieldRef instanceof FieldRef) {
				continue;
			}

			if ($fieldRef->getSource() instanceof RelationRef) {
				return true;
			}

			if ($selection->getSelectionKey() !== $fieldRef->getField()->getName()) {
				return true;
			}
		}

		return false;
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
		$slug = strtolower(
			preg_replace('/[^a-z0-9_]+/i', '_', implode('_', [...$path, $fieldName])) ?? 'field',
		);

		return 'l_' . $slug;
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
