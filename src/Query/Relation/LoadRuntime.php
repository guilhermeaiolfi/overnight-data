<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation;

use ON\Data\Database\QueryExecutorInterface;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Query\Exception\LoadRuntimeException;
use ON\Data\Query\Exception\RelationSelectionException;
use ON\Data\Query\Expression\AliasedExpression;
use ON\Data\Query\Expression\FieldRef;
use ON\Data\Query\Load\FetchPlan;
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

	private int $aliasCounter = 0;

	private RootLoadBranch $rootBranch;

	private RelationOutputProcessor $outputProcessor;

	public function __construct(
		private readonly SelectQuery $rootQuery,
		private readonly QueryExecutorInterface $executor,
		private readonly ?FetchPlan $fetchPlan = null,
	) {
		$this->rootBranch = new RootLoadBranch(
			$rootQuery,
			fn (string $fieldName): string => $this->allocateAlias(['root', 'required'], $fieldName),
		);
		$this->outputProcessor = new RelationOutputProcessor($fetchPlan?->getSchema());
	}

	public function fetchAll(): array
	{
		if ($this->rootQuery->getRelationSelections()->isEmpty()) {
			return $this->executor->fetchAll($this->rootQuery);
		}

		$this->prepare();
		$this->rootBranch->parseRows($this->executor->fetchAll($this->rootQuery));
		$this->runContinuationsFor($this->rootQuery);

		return $this->outputProcessor->processRoot($this->rootBranch);
	}

	public function fetchOne(): ?array
	{
		if ($this->rootQuery->getRelationSelections()->isEmpty()) {
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
			$branch->getNode()->parseRow(0, $this->orderedValues($row, $this->branchAliasTraversal($branch)));
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

	private function prepare(): void
	{
		if ($this->rootBranch->hasNode()) {
			return;
		}

		$this->prepareRootBranch();
		$this->createBranches();
		$this->configureBranches();
		$this->createParserTree();
		$this->selectBranchFields();
	}

	private function prepareRootBranch(): void
	{
		$this->rootBranch->registerPublicSelections();
		$this->rootBranch->requirePrimaryKey();
	}

	private function createBranches(): void
	{
		foreach ($this->rootQuery->getRelationSelections()->getAll() as $selection) {
			$key = $this->branchKey($selection->getPath());
			$parent = $selection->getParentPathKey() === null
				? $this->rootBranch
				: $this->branches[$selection->getParentPathKey()] ?? throw LoadRuntimeException::parentBranchMissing($selection->getRelationRef());

			$this->branches[$key] = new RelationLoadBranch($selection, $parent, $selection->getRelationRef()->getLoader());
		}
	}

	private function configureBranches(): void
	{
		$branches = array_values($this->branches);
		usort($branches, static fn (RelationLoadBranch $left, RelationLoadBranch $right): int => count($left->getRelationRef()->getPath()) <=> count($right->getRelationRef()->getPath()));

		foreach ($branches as $branch) {
			$query = $branch->getParent()->getQuery();
			$this->invokeLoaderMethod($branch, 'load', $query);

			if ($branch->getQuery()->getCollection()->getName() === '') {
				throw LoadRuntimeException::queryNotConfigured($branch->getRelationRef());
			}
		}
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

	private function selectBranchFields(): void
	{
		$branches = array_values($this->branches);
		usort($branches, static fn (RelationLoadBranch $left, RelationLoadBranch $right): int => count($left->getRelationRef()->getPath()) <=> count($right->getRelationRef()->getPath()));

		foreach ($branches as $branch) {
			$aliases = [];

			foreach ($branch->getSelections()->getByTag(SelectionTag::COLUMN) as $selection) {
				$fieldRef = $this->columnFieldRef($selection);

				if (! $fieldRef instanceof FieldRef) {
					continue;
				}

				$placeKey = $selection->getSelectionKey();
				$fieldName = $fieldRef->getField()->getName();
				$path = $fieldRef->getSource() instanceof RelationRef
					? $fieldRef->getSource()->getPath()
					: $branch->getRelationRef()->getPath();
				$loadKey = $this->branchLoadKey($branch, $selection, $fieldRef, $fieldName);
				$source = $this->resolveSelectionSource($branch, $fieldRef);

				$sqlKey = $this->ensureBranchFieldSelection(
					$branch,
					$branch->getQuery(),
					$source,
					$path,
					$fieldName,
					$loadKey,
				);

				$branch->bindPlaceToLoadKey($placeKey, $sqlKey);
				$aliases[] = $sqlKey;
			}

			$branch->getPublicNode()->setValueAliases($aliases);
		}
	}

	/**
	 * Load-local parser key for a branch column (proposal 0003 Phase 3).
	 * INTERNAL keys stay as planned; own fields use the field name; flats use a
	 * stable relative path key — place aliases are applied later by assemble.
	 */
	private function branchLoadKey(
		RelationLoadBranch $branch,
		SelectionItem $selection,
		FieldRef $fieldRef,
		string $fieldName,
	): string {
		if ($selection->hasTag(SelectionTag::INTERNAL)) {
			return $selection->getSelectionKey();
		}

		$level = $branch->getRelationRef();

		if ($fieldRef->getSource() === $level) {
			return $fieldName;
		}

		if (
			! $fieldRef->getSource() instanceof RelationRef
			|| $fieldRef->getSource()->getQuery() !== $level->getQuery()
		) {
			return $fieldName;
		}

		$levelPath = $level->getPath();
		$sourcePath = $fieldRef->getSource()->getPath();
		$relative = array_values(array_slice($sourcePath, count($levelPath)));

		return implode('__', [...$relative, $fieldName]);
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

				if (! $query instanceof SelectQuery) {
					continue;
				}

				$this->runContinuationsFor($query);
			}
		} finally {
			$this->flushingPendingContinuations = false;
		}
	}

	/**
	 * @param array<string, mixed> $row
	 * @param list<string> $aliases
	 * @return list<mixed>
	 */
	private function orderedValues(array $row, array $aliases): array
	{
		$ordered = [];

		foreach ($aliases as $alias) {
			$ordered[] = $row[$alias] ?? null;
		}

		return $ordered;
	}

	/**
	 * @return list<string>
	 */
	private function publicFieldsForSelection(RelationSelection $selection): array
	{
		if (! $selection->isLoaded()) {
			return [];
		}

		if ($selection->hasDefaultSelection()) {
			return $selection->getRelationRef()->getCollection()->getVisibleFields();
		}

		$names = [];

		foreach ($selection->getSelections()->getExplicit() as $item) {
			$fieldName = $this->columnFieldName($item);

			if ($fieldName === null) {
				continue;
			}

			$names[] = $fieldName;
		}

		return array_values(array_unique($names));
	}

	private function columnFieldName(SelectionItem $selection): ?string
	{
		$fieldRef = $this->columnFieldRef($selection);

		return $fieldRef?->getField()->getName();
	}

	private function columnFieldRef(SelectionItem $selection): ?FieldRef
	{
		$expression = $selection->getExpression();

		if ($expression instanceof AliasedExpression) {
			$expression = $expression->getExpression();
		}

		return $expression instanceof FieldRef ? $expression : null;
	}

	/**
	 * Resolve the query source that should provide a branch column selection.
	 * Own-level fields use the branch source; flat related fields join under this level.
	 */
	private function resolveSelectionSource(RelationLoadBranch $branch, FieldRef $field): QuerySourceInterface
	{
		$fieldSource = $field->getSource();
		$level = $branch->getRelationRef();

		if ($fieldSource === $level) {
			return $branch->getSource();
		}

		if (
			! $fieldSource instanceof RelationRef
			|| $fieldSource->getQuery() !== $level->getQuery()
		) {
			throw LoadRuntimeException::queryNotConfigured($level);
		}

		$levelPath = $level->getPath();
		$sourcePath = $fieldSource->getPath();

		if (
			count($sourcePath) <= count($levelPath)
			|| array_slice($sourcePath, 0, count($levelPath)) !== $levelPath
		) {
			throw LoadRuntimeException::queryNotConfigured($level);
		}

		// JOIN attachment (and other same-query graphs): reuse the original relation joins.
		if ($fieldSource->getQuery() === $branch->getQuery()) {
			return $fieldSource->getJoinedSource();
		}

		// SEPARATE query rooted at this level: remap the relative relation path.
		$relative = array_values(array_slice($sourcePath, count($levelPath)));
		$relation = null;

		foreach ($relative as $name) {
			$relation = $relation === null
				? $branch->getQuery()->relation($name)
				: $relation->relation($name);
		}

		if (! $relation instanceof RelationRef) {
			throw LoadRuntimeException::queryNotConfigured($level);
		}

		return $relation->getJoinedSource();
	}

	/**
	 * @param list<string> $path
	 */
	private function branchKey(array $path): string
	{
		return json_encode($path, JSON_THROW_ON_ERROR);
	}

	private function ensureBranchFieldSelection(
		RelationLoadBranch $branch,
		SelectQuery $query,
		QuerySourceInterface $source,
		array $path,
		string $fieldName,
		?string $loadAlias = null,
	): string {
		$loadAlias ??= $fieldName;

		if ($source === $query) {
			if ($loadAlias === $fieldName) {
				$query->select($query->field($fieldName));
			} elseif (! $query->getSelections()->hasNamedExpression($loadAlias)) {
				$query->select($query->field($fieldName)->as($loadAlias));
			}

			return $loadAlias;
		}

		// SEPARATE branch query: load-local aliases are safe on the level query.
		// JOIN attachment onto a parent query keeps allocated aliases to avoid root collisions.
		if (
			$branch->getSource() === $query
			&& $loadAlias !== ''
			&& ! $query->getSelections()->hasNamedExpression($loadAlias)
		) {
			$query->select($source->field($fieldName)->as($loadAlias));

			return $loadAlias;
		}

		$alias = $this->allocateAlias($path, $fieldName);

		if (! $query->getSelections()->hasNamedExpression($alias)) {
			$query->select($source->field($fieldName)->as($alias));
		}

		return $alias;
	}

	/**
	 * @param list<string> $path
	 */
	private function allocateAlias(array $path, string $fieldName): string
	{
		return sprintf(
			'__on_data_%s_%d',
			strtolower(preg_replace('/[^a-z0-9_]+/i', '_', implode('_', [...$path, $fieldName])) ?? 'field'),
			$this->aliasCounter++,
		);
	}

	/**
	 * @return list<string>
	 */
	private function branchAliasTraversal(RelationLoadBranch $branch): array
	{
		return $branch->getNode()->getValueAliasTraversal();
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
