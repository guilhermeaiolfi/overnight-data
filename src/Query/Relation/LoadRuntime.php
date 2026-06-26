<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation;

use LogicException;
use ON\Data\Database\QueryExecutorInterface;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Query\Exception\LoadRuntimeException;
use ON\Data\Query\Expression\AliasedExpression;
use ON\Data\Query\Expression\FieldRef;
use ON\Data\Query\Relation\Loader\LoaderInterface;
use ON\Data\Query\Result\Parser\AbstractNode;
use ON\Data\Query\Result\Parser\RootNode;
use ON\Data\Query\SelectQuery;
use function ON\Data\Query\x;

final class LoadRuntime
{
	/**
	 * @var array<string, LoadBranch>
	 */
	private array $branches = [];

	/**
	 * @var array<string, list<LoadBranch>>
	 */
	private array $linkedChildren = [];

	private ?LoadBranch $activeBranch = null;

	private int $aliasCounter = 0;

	private ?RootNode $rootNode = null;

	/**
	 * @var list<string>
	 */
	private array $rootColumns = [];

	/**
	 * @var list<string>
	 */
	private array $rootValueAliases = [];

	/**
	 * @var array<string, true>
	 */
	private array $rootPublicColumns = [];

	/**
	 * @var array<string, string>
	 */
	private array $rootFieldParserNames = [];

	public function __construct(
		private readonly SelectQuery $rootQuery,
		private readonly QueryExecutorInterface $executor,
	) {
	}

	public function fetchAll(): array
	{
		$this->buildPlan();
		$this->parseRootRows($this->executor->fetchAll($this->rootQuery));
		$this->loadLinkedChildren($this->rootQuery);

		return $this->cleanupRootRecords($this->requireRootNode()->getResult());
	}

	public function fetchOne(): ?array
	{
		$this->buildPlan();
		$row = $this->executor->fetchOne($this->rootQuery);

		if ($row === null) {
			return null;
		}

		$this->parseRootRows([$row]);
		$this->loadLinkedChildren($this->rootQuery);

		return $this->cleanupRootRecords($this->requireRootNode()->getResult())[0] ?? null;
	}

	public function register(AbstractNode $node): void
	{
		$branch = $this->requireActiveBranch();

		if ($branch->hasNode()) {
			throw LoadRuntimeException::nodeAlreadyRegistered($branch->getRelation());
		}

		$parentNode = $branch->getParentNode($this->requireRootNode());

		if ($branch->getStrategy() === LoadStrategy::JOIN) {
			$parentNode->joinNode($branch->getRelation()->getName(), $node);
		} else {
			$parentNode->linkNode($branch->getRelation()->getName(), $node);
		}

		$branch->setNode($node);
	}

	/**
	 * @return list<string>
	 */
	public function getNodeColumns(): array
	{
		return $this->requireActiveBranchMetadata('node columns', fn (LoadBranch $branch): array => $branch->getNodeColumns());
	}

	/**
	 * @return list<string>
	 */
	public function getNodeIdentityFields(): array
	{
		return $this->requireActiveBranchMetadata('node identity fields', fn (LoadBranch $branch): array => $branch->getNodeIdentityFields());
	}

	/**
	 * @return non-empty-list<string>
	 */
	public function getNodeParentFields(): array
	{
		return $this->requireActiveBranchMetadata('node parent fields', fn (LoadBranch $branch): array => $branch->getNodeParentFields());
	}

	/**
	 * @return non-empty-list<string>
	 */
	public function getNodeChildFields(): array
	{
		return $this->requireActiveBranchMetadata('node child fields', fn (LoadBranch $branch): array => $branch->getNodeChildFields());
	}

	private function buildPlan(): void
	{
		if ($this->rootNode instanceof RootNode) {
			return;
		}

		$this->planRootSelections();
		$this->reserveRootRelationParentFields();
		$this->rootNode = new RootNode($this->rootColumns, $this->rootIdentityFields());

		foreach ($this->rootQuery->getRelationSelections()->getAll() as $relation) {
			$this->planBranch($relation);
		}
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

			$this->rootColumns[] = $alias;
			$this->rootValueAliases[] = $alias;
			$this->rootPublicColumns[$alias] = true;

			$fieldExpression = $expression instanceof AliasedExpression
				? $expression->getExpression()
				: $expression;

			if ($fieldExpression instanceof FieldRef && $fieldExpression->getSource() === $this->rootQuery) {
				$this->rootFieldParserNames[$fieldExpression->getField()->getName()] = $alias;
			}
		}

		foreach ($this->rootPrimaryKeyFields() as $fieldName) {
			$this->ensureRootFieldParserName($fieldName);
		}
	}

	private function planBranch(RelationRef $relation): void
	{
		$key = $this->branchKey($relation);

		if (isset($this->branches[$key])) {
			return;
		}

		$parent = $relation->getParentRelation() === null
			? null
			: $this->branches[$this->branchKey($relation->getParentRelation())] ?? throw LoadRuntimeException::parentBranchMissing($relation);
		$loader = $relation->getLoader();
		$strategy = $loader->getDefaultLoadStrategy();
		$query = $strategy === LoadStrategy::JOIN
			? ($parent?->getQuery() ?? $this->rootQuery)
			: $this->rootQuery->related($relation->getCollection());
		$queryLocalRelation = $strategy === LoadStrategy::JOIN ? $this->resolveQueryLocalRelation($relation, $parent, $query) : null;
		$source = $strategy === LoadStrategy::JOIN
			? ($queryLocalRelation ?? throw LoadRuntimeException::queryLocalRelationMissing($relation))->getJoinedSource()
			: $query;
		$columns = $this->collectionFieldNames($relation->getCollection());
		$valueAliases = [];

		foreach ($columns as $fieldName) {
			if ($strategy === LoadStrategy::JOIN) {
				$alias = $this->allocateAlias($relation->getPath(), $fieldName);

				if (! $query->getSelections()->hasNamedExpression($alias)) {
					$query->select(($queryLocalRelation ?? throw LoadRuntimeException::queryLocalRelationMissing($relation))->field($fieldName)->as($alias));
				}

				$valueAliases[] = $alias;

				continue;
			}

			$query->select($query->field($fieldName));
			$valueAliases[] = $fieldName;
		}

		$nodeIdentityFields = $this->normalizeBranchFieldNames($relation->getCollection(), $relation->getCollection()->getPrimaryKey());
		$nodeParentFields = $this->prepareParentFields($relation, $parent, $loader);
		$nodeChildFields = $this->normalizeBranchFieldNames($relation->getCollection(), $loader->getChildKeyFields($relation));

		$branch = new LoadBranch(
			$relation,
			$parent,
			$loader,
			$strategy,
			$query,
			$source,
			$queryLocalRelation,
			$columns,
			$valueAliases,
			$nodeIdentityFields,
			$nodeParentFields,
			$nodeChildFields,
		);

		$this->branches[$key] = $branch;

		if ($strategy === LoadStrategy::SEPARATE_QUERY) {
			$this->linkedChildren[$this->queryIdentity($parent?->getQuery() ?? $this->rootQuery)][] = $branch;
		}

		$this->activeBranch = $branch;

		try {
			$loader->load($relation, $this);
		} finally {
			$this->activeBranch = null;
		}

		if (! $branch->hasNode()) {
			throw LoadRuntimeException::nodeNotRegistered($relation);
		}
	}

	private function reserveRootRelationParentFields(): void
	{
		foreach ($this->rootQuery->getRelationSelections()->getAll() as $relation) {
			if ($relation->getParentRelation() !== null) {
				continue;
			}

			foreach ($relation->getLoader()->getParentKeyFields($relation) as $fieldName) {
				$canonical = $this->rootQuery->getCollection()->getField($fieldName)->getName();
				$this->ensureRootFieldParserName($canonical);
			}
		}
	}

	private function resolveQueryLocalRelation(RelationRef $relation, ?LoadBranch $parent, SelectQuery $query): RelationRef
	{
		if ($parent === null || $parent->getStrategy() === LoadStrategy::SEPARATE_QUERY) {
			return $query->relation($relation->getName());
		}

		return ($parent->getQueryLocalRelation() ?? throw LoadRuntimeException::queryLocalRelationMissing($parent->getRelation()))
			->relation($relation->getName());
	}

	private function prepareParentFields(RelationRef $relation, ?LoadBranch $parent, LoaderInterface $loader): array
	{
		$parentFieldNames = $loader->getParentKeyFields($relation);
		$parentCollection = $parent?->getRelation()->getCollection() ?? $this->rootQuery->getCollection();

		if ($parent === null) {
			return $this->normalizeRootParentFields($parentCollection, $parentFieldNames);
		}

		return $this->normalizeBranchFieldNames($parentCollection, $parentFieldNames);
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

	private function loadLinkedChildren(SelectQuery $query): void
	{
		foreach ($this->linkedChildren[$this->queryIdentity($query)] ?? [] as $branch) {
			$node = $branch->getNode();
			$referenceValues = $node->getReferenceValues();

			if ($referenceValues === []) {
				continue;
			}

			$this->applyReferencePredicate($branch, $referenceValues);

			foreach ($this->executor->fetchAll($branch->getQuery()) as $row) {
				$node->parseRow(0, $this->orderedValues($row, $this->branchAliasTraversal($branch)));
			}

			$this->loadLinkedChildren($branch->getQuery());
		}
	}

	/**
	 * @param list<array<string, scalar>> $referenceValues
	 */
	private function applyReferencePredicate(LoadBranch $branch, array $referenceValues): void
	{
		$childFields = $branch->getNodeChildFields();

		foreach ($referenceValues as $values) {
			if (count($values) !== count($childFields)) {
				throw LoadRuntimeException::invalidReferenceValues($branch->getRelation());
			}
		}

		if (count($childFields) === 1) {
			$branch->getQuery()->where(
				x()->in(
					$branch->getQuery()->field($childFields[0]),
					array_map(static fn (array $values) => array_values($values)[0], $referenceValues),
				),
			);

			return;
		}

		$predicates = [];

		foreach ($referenceValues as $values) {
			$comparisons = [];

			foreach ($childFields as $index => $fieldName) {
				$comparisons[] = x()->eq($branch->getQuery()->field($fieldName), array_values($values)[$index]);
			}

			$predicates[] = x()->and(...$comparisons);
		}

		$branch->getQuery()->where(x()->or(...$predicates));
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
	 * @param list<array<string, mixed>> $records
	 * @return list<array<string, mixed>>
	 */
	private function cleanupRootRecords(array $records): array
	{
		$cleaned = [];

		foreach ($records as $record) {
			$item = [];

			foreach ($record as $key => $value) {
				if (! isset($this->rootPublicColumns[$key])) {
					continue;
				}

				$item[$key] = $value;
			}

			foreach ($this->rootQuery->getRelationSelections()->getAll() as $relation) {
				if ($relation->getParentRelation() !== null || ! array_key_exists($relation->getName(), $record)) {
					continue;
				}

				$item[$relation->getName()] = $record[$relation->getName()];
			}

			$cleaned[] = $item;
		}

		return $cleaned;
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
	 * @param non-empty-list<string> $fieldNames
	 * @return non-empty-list<string>
	 */
	private function normalizeBranchFieldNames(CollectionInterface $collection, array $fieldNames): array
	{
		return array_map(
			static fn (string $fieldName): string => $collection->getField($fieldName)->getName(),
			$fieldNames,
		);
	}

	/**
	 * @param non-empty-list<string> $fieldNames
	 * @return non-empty-list<string>
	 */
	private function normalizeRootParentFields(CollectionInterface $collection, array $fieldNames): array
	{
		$normalized = [];

		foreach ($fieldNames as $fieldName) {
			$canonical = $collection->getField($fieldName)->getName();
			$normalized[] = $this->ensureRootFieldParserName($canonical);
		}

		return $normalized;
	}

	/**
	 * @return non-empty-list<string>
	 */
	private function rootIdentityFields(): array
	{
		return array_map(
			fn (string $fieldName): string => $this->ensureRootFieldParserName($fieldName),
			$this->rootPrimaryKeyFields(),
		);
	}

	/**
	 * @return non-empty-list<string>
	 */
	private function rootPrimaryKeyFields(): array
	{
		return $this->normalizeBranchFieldNames($this->rootQuery->getCollection(), $this->rootQuery->getCollection()->getPrimaryKey());
	}

	/**
	 * @template T
	 * @param callable(LoadBranch): T $resolver
	 * @return T
	 */
	private function requireActiveBranchMetadata(string $name, callable $resolver): mixed
	{
		$branch = $this->activeBranch;

		if (! $branch instanceof LoadBranch) {
			throw LoadRuntimeException::activeBranchMetadataUnavailable($name);
		}

		return $resolver($branch);
	}

	private function requireActiveBranch(): LoadBranch
	{
		return $this->activeBranch ?? throw LoadRuntimeException::activeBranchMissing();
	}

	private function requireRootNode(): RootNode
	{
		return $this->rootNode ?? throw new LogicException('LoadRuntime root node is not built.');
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

	private function branchKey(RelationRef $relation): string
	{
		return json_encode($relation->getPath(), JSON_THROW_ON_ERROR);
	}

	private function queryIdentity(SelectQuery $query): string
	{
		return (string) spl_object_id($query);
	}

	private function ensureInternalFieldSelection(
		SelectQuery $query,
		string $fieldName,
		array $path,
	): string {
		$alias = $this->allocateAlias($path, $fieldName);

		if (! $query->getSelections()->hasNamedExpression($alias)) {
			$query->select($query->field($fieldName)->as($alias));
		}

		return $alias;
	}

	private function ensureRootFieldParserName(string $fieldName): string
	{
		if (isset($this->rootFieldParserNames[$fieldName])) {
			return $this->rootFieldParserNames[$fieldName];
		}

		$alias = $this->ensureInternalFieldSelection(
			$this->rootQuery,
			$fieldName,
			['root', 'required', $fieldName],
		);

		$this->rootFieldParserNames[$fieldName] = $alias;
		$this->rootColumns[] = $alias;
		$this->rootValueAliases[] = $alias;

		return $alias;
	}

	/**
	 * @return list<string>
	 */
	private function rootAliasTraversal(): array
	{
		$aliases = $this->rootValueAliases;

		foreach ($this->rootQuery->getRelationSelections()->getAll() as $relation) {
			if ($relation->getParentRelation() !== null) {
				continue;
			}

			$branch = $this->branches[$this->branchKey($relation)] ?? null;

			if (! $branch instanceof LoadBranch || $branch->getStrategy() !== LoadStrategy::JOIN) {
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
		$aliases = $branch->getNodeValueAliases();

		foreach ($this->rootQuery->getRelationSelections()->getAll() as $relation) {
			if ($relation->getParentRelation() !== $branch->getRelation()) {
				continue;
			}

			$child = $this->branches[$this->branchKey($relation)] ?? null;

			if (! $child instanceof LoadBranch || $child->getStrategy() !== LoadStrategy::JOIN) {
				continue;
			}

			array_push($aliases, ...$this->branchAliasTraversal($child));
		}

		return $aliases;
	}

	private function isInternalSelection(mixed $expression): bool
	{
		return $expression instanceof AliasedExpression
			&& str_starts_with($expression->getAlias(), '__on_data_');
	}
}
