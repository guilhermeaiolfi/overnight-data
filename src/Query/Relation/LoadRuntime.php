<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation;

use LogicException;
use ON\Data\Database\QueryExecutorInterface;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Query\Exception\LoadRuntimeException;
use ON\Data\Query\Expression\AliasedExpression;
use ON\Data\Query\Expression\FieldRef;
use ON\Data\Query\QuerySourceInterface;
use ON\Data\Query\Result\Parser\AbstractNode;
use ON\Data\Query\Result\Parser\RootNode;
use ON\Data\Query\SelectQuery;
use ReflectionMethod;

final class LoadRuntime
{
	/**
	 * @var array<string, LoadBranch>
	 */
	private array $branches = [];

	private ?LoadBranch $activeBranch = null;

	private ?string $activeMethod = null;

	private bool $registering = false;

	private bool $scheduledInInvocation = false;

	private ?SelectQuery $schedulingBoundaryQuery = null;

	/**
	 * @var array<int, SelectQuery>
	 */
	private array $pendingBoundaryQueries = [];

	private int $loaderInvocationDepth = 0;

	private bool $flushingPendingBoundaries = false;

	private int $aliasCounter = 0;

	private LoadBranch $rootBranch;

	public function __construct(
		private readonly SelectQuery $rootQuery,
		private readonly QueryExecutorInterface $executor,
	) {
		$this->rootBranch = LoadBranch::root($rootQuery);
		$this->rootBranch->setRootFieldResolver(fn (string $fieldName): string => $this->ensureRootFieldParserName($fieldName));
	}

	public function fetchAll(): array
	{
		$this->buildPlan();
		$this->parseRootRows($this->executor->fetchAll($this->rootQuery));
		$this->completeBoundary($this->rootQuery);

		return $this->rootBranch->projectRootRecords($this->requireRootNode()->getResult());
	}

	public function fetchOne(): ?array
	{
		$this->buildPlan();
		$row = $this->executor->fetchOne($this->rootQuery);

		if ($row === null) {
			return null;
		}

		$this->parseRootRows([$row]);
		$this->completeBoundary($this->rootQuery);

		return $this->rootBranch->projectRootRecords($this->requireRootNode()->getResult())[0] ?? null;
	}

	public function getCurrentBranch(): LoadBranch
	{
		return $this->requireActiveBranch();
	}

	public function requireParentBranch(): LoadBranch
	{
		return $this->requireActiveBranch()->getParent()
			?? throw LoadRuntimeException::activeBranchMissing();
	}

	/**
	 * @return list<string>
	 */
	public function getNodeColumns(): array
	{
		return $this->getCurrentBranch()->getNodeColumns();
	}

	public function getNode(): AbstractNode
	{
		return $this->requireActiveBranch()->getNode();
	}

	public function getParentNode(): AbstractNode
	{
		$branch = $this->requireActiveBranch();
		$parent = $branch->getParent();

		return $parent?->getNode() ?? throw LoadRuntimeException::activeBranchMissing();
	}

	public function setJoinedAttachment(bool $joined): void
	{
		$this->requireActiveBranch()->setJoinedAttachment($joined);
	}

	public function getQuery(): SelectQuery
	{
		return $this->requireActiveBranch()->getQuery();
	}

	public function getSource(): QuerySourceInterface
	{
		return $this->requireActiveBranch()->getSource();
	}

	public function getQueryRelation(): RelationRef
	{
		$branch = $this->requireActiveBranch();
		$parent = $branch->getParent() ?? throw LoadRuntimeException::activeBranchMissing();
		$query = $parent->getQuery();

		if ($parent->isRoot() || $parent->getQueryLocalRelation() === null) {
			return $query->relation($branch->getRelation()->getName());
		}

		return $parent->getQueryLocalRelation()
			->relation($branch->getRelation()->getName());
	}

	public function createQuery(CollectionInterface $collection): SelectQuery
	{
		return $this->rootQuery->related($collection);
	}

	public function setQueryContext(
		SelectQuery $query,
		QuerySourceInterface $source,
		?RelationRef $queryLocalRelation = null,
	): void {
		$this->requireActiveBranch()->setQueryContext($query, $source, $queryLocalRelation);
	}

	/**
	 * @return list<array<string, scalar>>
	 */
	public function getReferenceValues(): array
	{
		return $this->requireActiveBranch()->getNode()->getReferenceValues();
	}

	public function nextPass(string $method = 'load'): void
	{
		if ($this->registering) {
			throw LoadRuntimeException::nextPassNotAllowedDuringRegister($this->requireActiveBranch()->getRelation());
		}

		if ($this->scheduledInInvocation) {
			throw LoadRuntimeException::multipleNextPasses($this->requireActiveBranch()->getRelation(), $this->activeMethod ?? 'load');
		}

		$this->assertSchedulableMethod($method);
		$this->requireActiveBranch()->schedule(
			$method,
			$this->schedulingBoundaryQuery ?? throw LoadRuntimeException::scheduleBoundaryMissing($this->requireActiveBranch()->getRelation()),
		);
		$this->scheduledInInvocation = true;
	}

	public function execute(SelectQuery $query): void
	{
		$branch = $this->requireActiveBranch();
		$rows = $this->executor->fetchAll($query);

		if ($query === $branch->getQuery()) {
			foreach ($rows as $row) {
				$branch->getNode()->parseRow(0, $this->orderedValues($row, $this->branchAliasTraversal($branch)));
			}
		}

		$this->schedulingBoundaryQuery = $query;
		$this->pendingBoundaryQueries[spl_object_id($query)] = $query;
	}

	public function getLoadStrategy(LoadStrategy $default): LoadStrategy
	{
		return $default;
	}

	public function registerChildBranches(): void
	{
		$parent = $this->requireActiveBranch();

		foreach ($parent->getChildren() as $child) {
			$this->registerBranch($child);
		}
	}

	/**
	 * @return list<LoadBranch>
	 */
	public function getChildBranches(): array
	{
		return $this->requireActiveBranch()->getChildren();
	}

	private function buildPlan(): void
	{
		if ($this->rootBranch->hasNode()) {
			return;
		}

		$this->planRootSelections();
		$this->buildBranchSkeletons();
		$this->loadBranches();
		$this->registerParserTree();
		$this->finalizeBranchSelections();
	}

	private function planRootSelections(): void
	{
		$publicSelections = array_values(array_filter(
			$this->rootQuery->getSelections()->getExplicit(),
			fn ($selection): bool => ! $this->isInternalSelection($selection->getExpression()),
		));

		if ($publicSelections === []) {
			foreach ($this->rootQuery->getCollection()->getVisibleFields() as $fieldName) {
				$field = $this->rootQuery->field($fieldName);
				$this->rootQuery->select($field);
				$publicSelections = $this->rootQuery->getSelections()->getExplicit();
			}
		}

		foreach ($publicSelections as $selection) {
			$expression = $selection->getExpression();
			$alias = $expression instanceof AliasedExpression
				? $expression->getAlias()
				: implode('.', $expression->getPath());

			$fieldExpression = $expression instanceof AliasedExpression
				? $expression->getExpression()
				: $expression;

			$fieldName = null;
			if ($fieldExpression instanceof FieldRef && $fieldExpression->getSource() === $this->rootQuery) {
				$fieldName = $fieldExpression->getField()->getName();
			}

			$this->rootBranch->addRootPublicColumn($alias, $fieldName);
		}

		foreach ($this->rootPrimaryKeyFields() as $fieldName) {
			$this->ensureRootFieldParserName($fieldName);
		}
	}

	private function buildBranchSkeletons(): void
	{
		foreach ($this->rootQuery->getRelationSelections()->getAll() as $selection) {
			$key = $this->branchKey($selection->getPath());
			$parent = $selection->getParentPathKey() === null
				? $this->rootBranch
				: $this->branches[$selection->getParentPathKey()] ?? throw LoadRuntimeException::parentBranchMissing($selection->getRelation());

			$this->branches[$key] = LoadBranch::relation(
				$selection,
				$parent,
				$selection->getRelation()->getLoader(),
				$this->publicFieldsForSelection($selection),
			);
		}
	}

	private function loadBranches(): void
	{
		$branches = array_values($this->branches);
		usort($branches, static fn (LoadBranch $left, LoadBranch $right): int => count($left->getRelation()->getPath()) <=> count($right->getRelation()->getPath()));

		foreach ($branches as $branch) {
			$boundary = $branch->getParent()?->getQuery() ?? throw LoadRuntimeException::activeBranchMissing();
			$this->invokeLoaderMethod($branch, 'load', $boundary);

			if ($branch->getQuery()->getCollection()->getName() === '') {
				throw LoadRuntimeException::queryNotConfigured($branch->getRelation());
			}
		}
	}

	private function registerParserTree(): void
	{
		$rootNode = new RootNode($this->rootBranch->getRootColumns(), $this->rootIdentityFields());
		$this->rootBranch->setNode($rootNode);

		foreach ($this->rootBranch->getChildren() as $branch) {
			$this->registerBranch($branch);
		}

		foreach ($this->rootBranch->getChildren() as $branch) {
			$node = $branch->getNode();

			if ($branch->isJoinedAttachment()) {
				$rootNode->joinNode($branch->getRelation()->getName(), $node);

				continue;
			}

			$rootNode->linkNode($branch->getRelation()->getName(), $node);
		}
	}

	private function finalizeBranchSelections(): void
	{
		$branches = array_values($this->branches);
		usort($branches, static fn (LoadBranch $left, LoadBranch $right): int => count($left->getRelation()->getPath()) <=> count($right->getRelation()->getPath()));

		foreach ($branches as $branch) {
			$aliases = [];

			foreach ($branch->getNodeColumns() as $fieldName) {
				$aliases[] = $this->ensureBranchFieldSelection(
					$branch->getQuery(),
					$branch->getSource(),
					$branch->getRelation()->getPath(),
					$fieldName,
				);
			}

			$branch->getPublicNode()->setValueAliases($aliases);
		}
	}

	private function registerBranch(LoadBranch $branch): AbstractNode
	{
		$previousBranch = $this->activeBranch;
		$previousMethod = $this->activeMethod;
		$previousRegistering = $this->registering;
		$this->activeBranch = $branch;
		$this->activeMethod = 'register';
		$this->registering = true;

		try {
			$node = $branch->getLoader()->register($branch->getRelation(), $this);
		} finally {
			$this->registering = $previousRegistering;
			$this->activeMethod = $previousMethod;
			$this->activeBranch = $previousBranch;
		}

		if (! $node instanceof AbstractNode) {
			throw LoadRuntimeException::nodeNotRegistered($branch->getRelation());
		}

		$branch->setNode($node);

		return $node;
	}

	private function invokeLoaderMethod(LoadBranch $branch, string $method, SelectQuery $boundaryQuery): void
	{
		$loader = $branch->getLoader();
		$previousBranch = $this->activeBranch;
		$previousMethod = $this->activeMethod;
		$previousScheduledInInvocation = $this->scheduledInInvocation;
		$previousSchedulingBoundaryQuery = $this->schedulingBoundaryQuery;
		$this->activeBranch = $branch;
		$this->activeMethod = $method;
		$this->scheduledInInvocation = false;
		$this->schedulingBoundaryQuery = $boundaryQuery;
		$this->loaderInvocationDepth++;
		$branch->clearSchedule();

		try {
			$loader->{$method}($branch->getRelation(), $this);
		} finally {
			$this->loaderInvocationDepth--;
			$this->activeBranch = $previousBranch;
			$this->activeMethod = $previousMethod;
			$this->scheduledInInvocation = $previousScheduledInInvocation;
			$this->schedulingBoundaryQuery = $previousSchedulingBoundaryQuery;
		}

		if ($this->loaderInvocationDepth === 0) {
			$this->flushPendingBoundaries();
		}
	}

	private function completeBoundary(SelectQuery $query): void
	{
		do {
			$ran = false;

			foreach ($this->branches as $branch) {
				if ($branch->getScheduledBoundaryQuery() !== $query) {
					continue;
				}

				$method = $branch->getScheduledMethod();

				if ($method === null) {
					continue;
				}

				$branch->clearSchedule();
				$this->invokeLoaderMethod($branch, $method, $query);
				$ran = true;
			}
		} while ($ran);
	}

	private function flushPendingBoundaries(): void
	{
		if ($this->flushingPendingBoundaries) {
			return;
		}

		$this->flushingPendingBoundaries = true;

		try {
			while ($this->pendingBoundaryQueries !== []) {
				$key = array_key_first($this->pendingBoundaryQueries);

				if ($key === null) {
					break;
				}

				$query = $this->pendingBoundaryQueries[$key];
				unset($this->pendingBoundaryQueries[$key]);

				if (! $query instanceof SelectQuery) {
					continue;
				}

				$this->completeBoundary($query);
			}
		} finally {
			$this->flushingPendingBoundaries = false;
		}
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 */
	private function parseRootRows(array $rows): void
	{
		$rootNode = $this->requireRootNode();
		$aliases = $this->rootAliasTraversal();

		foreach ($rows as $row) {
			$rootNode->parseRow(0, $this->orderedValues($row, $aliases));
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
	private function collectionFieldNames(CollectionInterface $collection): array
	{
		$fields = [];

		foreach ($collection->getFields() as $field) {
			$fields[] = $field->getName();
		}

		return $fields;
	}

	/**
	 * @return list<string>
	 */
	private function publicFieldsForSelection(RelationSelection $selection): array
	{
		if (! $selection->isLoaded()) {
			return [];
		}

		if ($selection->getFields() !== null) {
			return $selection->getFields();
		}

		return $selection->getRelation()->getCollection()->getVisibleFields();
	}

	/**
	 * @param non-empty-list<string> $fieldNames
	 * @return non-empty-list<string>
	 */
	private function normalizeFieldNames(CollectionInterface $collection, array $fieldNames): array
	{
		return array_map(
			static fn (string $fieldName): string => $collection->getField($fieldName)->getName(),
			$fieldNames,
		);
	}

	/**
	 * @return non-empty-list<string>
	 */
	private function rootIdentityFields(): array
	{
		return array_map(
			$this->ensureRootFieldParserName(...),
			$this->rootPrimaryKeyFields(),
		);
	}

	/**
	 * @return non-empty-list<string>
	 */
	private function rootPrimaryKeyFields(): array
	{
		return $this->normalizeFieldNames($this->rootQuery->getCollection(), $this->rootQuery->getCollection()->getPrimaryKey());
	}

	private function requireActiveBranch(): LoadBranch
	{
		return $this->activeBranch ?? throw LoadRuntimeException::activeBranchMissing();
	}

	private function requireRootNode(): RootNode
	{
		return $this->rootBranch->hasNode()
			? $this->rootBranch->getRootNode()
			: throw new LogicException('LoadRuntime root node is not built.');
	}

	/**
	 * @param list<string> $path
	 */
	private function branchKey(array $path): string
	{
		return json_encode($path, JSON_THROW_ON_ERROR);
	}

	private function ensureBranchFieldSelection(
		SelectQuery $query,
		QuerySourceInterface $source,
		array $path,
		string $fieldName,
	): string {
		if ($source === $query) {
			$query->select($query->field($fieldName));

			return $fieldName;
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

	private function ensureRootFieldParserName(string $fieldName): string
	{
		if ($this->rootBranch->hasRootFieldParserName($fieldName)) {
			return $this->rootBranch->getRootFieldParserName($fieldName) ?? throw new LogicException('Root field parser alias is not configured.');
		}

		$alias = $this->allocateAlias(['root', 'required'], $fieldName);

		if (! $this->rootQuery->getSelections()->hasNamedExpression($alias)) {
			$this->rootQuery->select($this->rootQuery->field($fieldName)->as($alias));
		}

		$this->rootBranch->setRootFieldParserName($fieldName, $alias);

		return $alias;
	}

	/**
	 * @return list<string>
	 */
	private function rootAliasTraversal(): array
	{
		$aliases = $this->rootBranch->getRootValueAliases();

		foreach ($this->rootBranch->getChildren() as $branch) {
			if ($branch->getQuery() !== $this->rootQuery) {
				continue;
			}

			array_push($aliases, ...$this->branchAliasTraversal($branch));
		}

		return $aliases;
	}

	/**
	 * @return list<string>
	 */
	private function branchAliasTraversal(LoadBranch $branch): array
	{
		return $branch->getNode()->getValueAliasTraversal();
	}

	private function assertSchedulableMethod(string $method): void
	{
		$loader = $this->requireActiveBranch()->getLoader();

		if ($method === 'register' || $method === 'join') {
			throw LoadRuntimeException::invalidScheduledMethod($this->requireActiveBranch()->getRelation(), $method);
		}

		if (! method_exists($loader, $method)) {
			throw LoadRuntimeException::invalidScheduledMethod($this->requireActiveBranch()->getRelation(), $method);
		}

		$reflection = new ReflectionMethod($loader, $method);

		if (! $reflection->isPublic() || $reflection->getNumberOfParameters() !== 2) {
			throw LoadRuntimeException::invalidScheduledMethod($this->requireActiveBranch()->getRelation(), $method);
		}
	}

	private function isInternalSelection(mixed $expression): bool
	{
		return $expression instanceof AliasedExpression
			&& str_starts_with($expression->getAlias(), '__on_data_');
	}
}
