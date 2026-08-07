<?php

declare(strict_types=1);

namespace ON\Data\Query;

use InvalidArgumentException;
use ON\Data\Database\Exception\QueryNotExecutableException;
use ON\Data\Database\QueryExecutorInterface;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Field\FieldInterface;
use ON\Data\Definition\Relation\RelationInterface;
use ON\Data\Key;
use function ON\Data\Mapper\map;
use ON\Data\ORM\Representation\Schema\Query\QueryRepresentationSchemaCompiler;
use ON\Data\ORM\Representation\Schema\RepresentationSchema;
use ON\Data\Query\Condition\ConditionInterface;
use ON\Data\Query\Condition\ConditionList;
use ON\Data\Query\Condition\ConditionTag;
use ON\Data\Query\Exception\CountRequiresRootIdentityException;
use ON\Data\Query\Exception\ObjectExportException;
use ON\Data\Query\Exception\RelationSelectionException;
use ON\Data\Query\Exception\UnknownQueryExpressionException;
use ON\Data\Query\Exception\UnknownQueryFieldException;
use ON\Data\Query\Exception\UnknownQueryMemberException;
use ON\Data\Query\Exception\UnknownQueryRelationException;
use ON\Data\Query\Expression\AliasedExpression;
use ON\Data\Query\Expression\FieldRef;
use ON\Data\Query\Expression\SourceFieldExpression;
use ON\Data\Query\Expression\StarExpression;
use ON\Data\Query\Expression\SubqueryExpression;
use ON\Data\Query\Expression\ValueExpressionInterface;
use ON\Data\Query\Load\FetchPlan;
use ON\Data\Query\Load\LoadGraphBuilder;
use ON\Data\Query\Relation\RelationQueryPlanner;
use ON\Data\Query\Relation\RelationRef;
use ON\Data\Query\Relation\RelationSelection;
use ON\Data\Query\Relation\RelationSelectionTree;
use ON\Data\Query\Result\ObjectExportClassValidator;
use ON\Data\Query\Result\WritablePreparation;
use ON\Data\Query\Result\WritableResultHandler;
use ON\Data\Query\Selection\SelectionList;
use ON\Data\Query\Selection\SelectionTag;
use ON\Data\Query\Sort\Sort;

final class SelectQuery implements QuerySourceInterface
{
	private const COUNT_AGGREGATE_ALIAS = '__count';

	private const COUNT_DERIVED_ALIAS = 'count_rows';

	private const COUNT_ROW_LITERAL_ALIAS = '__count_row';

	/**
	 * @var array<string, FieldRef>
	 */
	private array $fieldRefs = [];

	/**
	 * @var array<string, RelationRef>
	 */
	private array $relationRefs = [];

	/**
	 * @var list<Join>
	 */
	private array $joins = [];

	private ?StarExpression $star = null;

	private readonly SelectionList $selections;

	private readonly ConditionList $conditions;

	/**
	 * @var list<ValueExpressionInterface>
	 */
	private array $groups = [];

	/**
	 * @var list<ConditionInterface>
	 */
	private array $havingConditions = [];

	/**
	 * @var list<Sort>
	 */
	private array $sorts = [];

	private ?int $limit = null;

	private ?int $offset = null;

	private ?StarExpression $sourceStar = null;

	private ?string $resultClass = null;

	private ?WritableResultHandler $writableHandler = null;

	private ?Relation\LoadRuntime $runtime = null;

	private ?FetchPlan $fetchPlan = null;

	public function __construct(
		private readonly CollectionInterface|DerivedSelectQuery $source,
		private ?QueryExecutorInterface $executor = null,
	) {
		$this->selections = new SelectionList();
		$this->selections->add($this->all(), SelectionTag::DEFAULT, true);
		$this->conditions = new ConditionList();
	}

	public function getQuery(): SelectQuery
	{
		return $this;
	}

	public function getCollection(): CollectionInterface
	{
		if ($this->source instanceof CollectionInterface) {
			return $this->source;
		}

		throw new InvalidArgumentException('Derived query sources do not expose collection metadata.');
	}

	public function getFrom(): CollectionInterface|DerivedSelectQuery
	{
		return $this->source;
	}

	public function getSourceName(): string
	{
		if ($this->source instanceof CollectionInterface) {
			return $this->source->getName();
		}

		return $this->source->getAlias();
	}

	public function getPath(): array
	{
		return [];
	}

	public function isExecutable(): bool
	{
		return $this->source instanceof CollectionInterface && $this->executor instanceof QueryExecutorInterface;
	}

	public function detach(): self
	{
		$this->executor = null;
		$this->runtime = null;

		return $this;
	}

	public function field(string $name): FieldRef|SourceFieldExpression
	{
		$name = trim($name);

		if ($name === '') {
			throw new InvalidArgumentException('SelectQuery::field() requires a non-empty field name.');
		}

		if ($this->source instanceof DerivedSelectQuery) {
			return $this->source->field($name);
		}

		if (isset($this->fieldRefs[$name])) {
			return $this->fieldRefs[$name];
		}

		$field = $this->source->getField($name);

		if (! $field instanceof FieldInterface) {
			throw UnknownQueryFieldException::forDefinition($name, $this->source->getName());
		}

		return $this->fieldRefs[$name] = new FieldRef($this, $field);
	}

	public function relation(string $name): RelationRef
	{
		if (! $this->canLoadRelations()) {
			throw new InvalidArgumentException('Derived query sources do not support relation loading.');
		}

		if (isset($this->relationRefs[$name])) {
			return $this->relationRefs[$name];
		}

		$relation = $this->source->getRelation($name);

		if (! $relation instanceof RelationInterface) {
			throw UnknownQueryRelationException::forDefinition($name, $this->source->getName());
		}

		return $this->relationRefs[$name] = new RelationRef($this, $relation);
	}

	public function __get(string $name): FieldRef|RelationRef|ValueExpressionInterface
	{
		if ($this->source instanceof DerivedSelectQuery) {
			return $this->field($name);
		}

		if ($this->source->hasField($name)) {
			return $this->field($name);
		}

		if ($this->source->hasRelation($name)) {
			return $this->relation($name);
		}

		throw UnknownQueryMemberException::forDefinition($name, $this->source->getName());
	}

	public function star(): StarExpression
	{
		if ($this->source instanceof DerivedSelectQuery) {
			return $this->sourceStar ??= new StarExpression($this->source);
		}

		return $this->star ??= new StarExpression($this);
	}

	public function all(): StarExpression
	{
		return $this->star();
	}

	public function as(?string $alias = null): DerivedSelectQuery
	{
		DerivedOutputColumns::assertUniqueNames($this);

		return new DerivedSelectQuery($this, $alias);
	}

	public function copy(): self
	{
		return $this->rebind(SourceMap::empty());
	}

	/**
	 * Allocate local join counterparts, compose them with the inherited anchor
	 * map, then rebind payloads. Nested relation sources resolve structurally
	 * from the anchored query when touched.
	 */
	public function rebind(SourceMap $sources): self
	{
		$copy = new self($this->source, $this->executor);
		$map = $sources->with($this, $copy);

		foreach ($this->joins as $join) {
			$source = $map->remap($join->getSource());
			$copiedJoin = new Join($copy, $source, $join->getCollection(), $join->getType(), $join->getName());
			$copy->joins[] = $copiedJoin;
			$map = $map->with($join, $copiedJoin);
		}

		$copy->selections->clear();
		$copy->selections->merge($this->selections->projectTo($map));
		$copy->conditions->clear();
		foreach ($this->conditions->rebind($map)->getItems() as $item) {
			$copy->conditions->add($item->getCondition(), ...$item->getTags());
		}

		foreach ($this->joins as $join) {
			$join->rebind($map);
		}

		$copy->groups = array_map(
			static fn (ValueExpressionInterface $group): ValueExpressionInterface => $group->rebind($map),
			$this->groups
		);
		$copy->havingConditions = array_map(
			static fn (ConditionInterface $condition): ConditionInterface => $condition->rebind($map),
			$this->havingConditions
		);
		$copy->sorts = array_map(static fn (Sort $sort): Sort => $sort->rebind($map), $this->sorts);

		foreach ($this->relationRefs as $relation) {
			$relation->rebind($map);
		}

		$copy->limit = $this->limit;
		$copy->offset = $this->offset;
		$copy->resultClass = $this->resultClass;
		$copy->writableHandler = $this->writableHandler;
		$copy->fetchPlan = null;

		return $copy;
	}

	public function select(ValueExpressionInterface|AliasedExpression|StarExpression|SelectQuery|RelationRef ...$expressions): self
	{
		if ($expressions === []) {
			throw new InvalidArgumentException('SelectQuery::select() requires at least one expression.');
		}

		$normalized = [];

		foreach ($expressions as $expression) {
			if ($expression instanceof RelationRef) {
				if ($expression->getQuery() !== $this) {
					throw RelationSelectionException::foreignQueryRelation($expression, $this);
				}

				// Bare RelationRef loads all visible fields; already-configured refs keep their options.
				if (! $expression->isSelected()) {
					$expression->load();
				}

				continue;
			}

			$normalized[] = $expression instanceof SelectQuery
				? new SubqueryExpression($expression)
				: $expression;
		}

		if ($normalized !== []) {
			$this->selections->removeByTag(SelectionTag::DEFAULT);
			$this->selections->addExplicit($normalized);
		}

		$this->assertNoRelationSelectionCollisions();

		return $this;
	}

	public function require(FieldRef|ValueExpressionInterface|AliasedExpression|SelectQuery $field, string $tag): self
	{
		$expression = $field instanceof SelectQuery
			? new SubqueryExpression($field)
			: $field;

		$this->selections->add($expression, $tag);

		return $this;
	}

	public function where(ConditionInterface ...$conditions): self
	{
		if ($conditions === []) {
			throw new InvalidArgumentException('SelectQuery::where() requires at least one condition.');
		}

		$this->conditions->addAll($conditions, ConditionTag::USER);

		return $this;
	}

	public function groupBy(ValueExpressionInterface|SelectQuery ...$expressions): self
	{
		if ($expressions === []) {
			throw new InvalidArgumentException('SelectQuery::groupBy() requires at least one expression.');
		}

		array_push($this->groups, ...array_map($this->normalizeValueExpression(...), $expressions));

		return $this;
	}

	public function having(ConditionInterface ...$conditions): self
	{
		if ($conditions === []) {
			throw new InvalidArgumentException('SelectQuery::having() requires at least one condition.');
		}

		array_push($this->havingConditions, ...$conditions);

		return $this;
	}

	public function orderBy(Sort ...$sorts): self
	{
		if ($sorts === []) {
			throw new InvalidArgumentException('SelectQuery::orderBy() requires at least one sort.');
		}

		array_push($this->sorts, ...$sorts);

		return $this;
	}

	public function limit(?int $limit): self
	{
		if ($limit !== null && $limit < 0) {
			throw new InvalidArgumentException('SelectQuery::limit() requires a non-negative integer or null.');
		}

		$this->limit = $limit;

		return $this;
	}

	public function offset(?int $offset): self
	{
		if ($offset !== null && $offset < 0) {
			throw new InvalidArgumentException('SelectQuery::offset() requires a non-negative integer or null.');
		}

		$this->offset = $offset;

		return $this;
	}

	public function to(string $class): self
	{
		ObjectExportClassValidator::assertSupported($class);

		$this->resultClass = $class;

		return $this;
	}

	/**
	 * Compile this select into a structural RepresentationSchema for Session::update/create.
	 * Does not execute the query.
	 */
	public function projection(): RepresentationSchema
	{
		return (new QueryRepresentationSchemaCompiler())->compile($this);
	}

	public function getResultClass(): ?string
	{
		return $this->resultClass;
	}

	public function writable(WritableResultHandler $handler): self
	{
		if ($this->resultClass === null) {
			throw ObjectExportException::requiresObjectExport();
		}

		ObjectExportClassValidator::assertWritable($this->resultClass);

		$this->writableHandler = $handler;

		return $this;
	}

	public function isWritable(): bool
	{
		return $this->writableHandler !== null;
	}

	public function getWritableResultHandler(): ?WritableResultHandler
	{
		return $this->writableHandler;
	}

	/**
	 * Place + fetch snapshot from the last {@see fetchAll()} / {@see fetchOne()} begin.
	 *
	 * Compiled before LoadRuntime runs (proposal 0003 Phase 1). Null until a fetch starts.
	 */
	public function getFetchPlan(): ?FetchPlan
	{
		return $this->fetchPlan;
	}

	public function getSelections(): SelectionList
	{
		return $this->selections;
	}

	public function getRelationSelections(): RelationSelectionTree
	{
		$tree = $this->buildRelationSelections();
		$this->assertNoRelationSelectionCollisions($tree);

		return $tree;
	}

	public function get(string $name): ValueExpressionInterface
	{
		$name = trim($name);

		if ($name === '') {
			throw new InvalidArgumentException('SelectQuery::get() requires a non-empty expression name.');
		}

		if (! $this->selections->hasNamedExpression($name)) {
			throw UnknownQueryExpressionException::forQuery($name, $this->getSourceName());
		}

		return $this->selections->getNamedExpression($name);
	}

	public function join(
		CollectionInterface $collection,
		JoinType $type = JoinType::INNER,
		?string $name = null,
		?QuerySourceInterface $source = null,
	): Join {
		$source ??= $this;

		if ($source->getQuery() !== $this) {
			throw new InvalidArgumentException(sprintf(
				'Join source "%s" belongs to a different SelectQuery.',
				$this->describeSource($source),
			));
		}

		$name = trim($name ?? $collection->getName());

		if ($name === '') {
			throw new InvalidArgumentException('Join name cannot be empty.');
		}

		if ($source instanceof RelationRef) {
			$source = $source->getJoinedSource();
		}

		$this->assertJoinNameAvailable($name);

		$join = new Join($this, $source, $collection, $type, $name);
		$this->joins[] = $join;

		return $join;
	}

	/**
	 * @return list<Join>
	 */
	public function getJoins(): array
	{
		return $this->joins;
	}

	/**
	 * @return list<ConditionInterface>
	 */
	public function getConditions(): array
	{
		return $this->conditions->getAll();
	}

	public function getConditionList(): ConditionList
	{
		return $this->conditions;
	}

	/**
	 * @return list<ValueExpressionInterface>
	 */
	public function getGroups(): array
	{
		return $this->groups;
	}

	/**
	 * @return list<ConditionInterface>
	 */
	public function getHavingConditions(): array
	{
		return $this->havingConditions;
	}

	/**
	 * @return list<Sort>
	 */
	public function getSorts(): array
	{
		return $this->sorts;
	}

	public function getLimit(): ?int
	{
		return $this->limit;
	}

	public function getOffset(): ?int
	{
		return $this->offset;
	}

	/**
	 * @return list<array<string, mixed>>|list<object>
	 */
	public function fetchAll(): array
	{
		$handler = $this->writableHandler;
		$preparation = $this->beginFetch($handler);
		$runtime = $this->getLoadRuntime(fresh: true);
		$rows = $runtime->fetchAll();
		$publicRows = $this->publicRows($rows);

		if ($this->resultClass === null) {
			$materialized = $publicRows;
		} else {
			/** @var list<object> $materialized */
			$materialized = map($publicRows)->collection()->to($this->resultClass);
		}

		if ($handler !== null && $preparation !== null) {
			$handler->track($this, $preparation, $rows, $materialized);
		}

		return $materialized;
	}

	/**
	 * Count matching root rows (or groups) for the current live query without mutating it.
	 *
	 * Copies the query, applies scalar count normalization (no result class, writable
	 * handler, order/limit/offset, or relation hydration), keeps joins already on the
	 * query, reshapes to an aggregate select, then runs ordinary {@see fetchOne()}.
	 * Grouped and composite-key shapes wrap via {@see as()} + outer `COUNT(*)`.
	 * Joins needed by predicates/grouping rematerialize during normal translation.
	 */
	public function count(): int
	{
		if ($this->executor === null) {
			throw QueryNotExecutableException::forQuery($this);
		}

		$countQuery = $this->copy();
		$countQuery->sorts = [];
		$countQuery->limit = null;
		$countQuery->offset = null;
		$countQuery->resultClass = null;
		$countQuery->writableHandler = null;
		$countQuery->runtime = null;

		foreach ($countQuery->relationRefs as $relation) {
			$relation->clearSelection();
		}

		$countQuery->selections->clear();
		$executable = $countQuery;

		if ($countQuery->groups !== [] || $countQuery->havingConditions !== []) {
			// Count groups after HAVING: SELECT 1 … GROUP BY/HAVING, then outer COUNT(*).
			$countQuery->selections->addExplicit([x()->literal(1)->as(self::COUNT_ROW_LITERAL_ALIAS)]);
			$executable = new self($countQuery->as(self::COUNT_DERIVED_ALIAS), $this->executor);
			$executable->select(x()->count($executable->all())->as(self::COUNT_AGGREGATE_ALIAS));
		} else {
			$primaryKeyFields = $this->getRootPrimaryKeyFields($countQuery);

			if ($primaryKeyFields === []) {
				throw CountRequiresRootIdentityException::forQuery($this);
			}

			if (count($primaryKeyFields) === 1) {
				// Single PK: COUNT(DISTINCT pk) so joins do not inflate the total.
				$countQuery->selections->addExplicit([
					$primaryKeyFields[0]->countDistinct()->as(self::COUNT_AGGREGATE_ALIAS),
				]);
			} else {
				// Composite PK: GROUP BY every PK column, then outer COUNT(*).
				$countQuery->selections->addExplicit([x()->literal(1)->as(self::COUNT_ROW_LITERAL_ALIAS)]);
				$countQuery->groups = $primaryKeyFields;
				$executable = new self($countQuery->as(self::COUNT_DERIVED_ALIAS), $this->executor);
				$executable->select(x()->count($executable->all())->as(self::COUNT_AGGREGATE_ALIAS));
			}
		}

		$row = $executable->fetchOne();

		return is_array($row) ? (int) ($row[self::COUNT_AGGREGATE_ALIAS] ?? 0) : 0;
	}

	/**
	 * Fetch at most one row. When $identity is provided, constrain by the root
	 * collection primary key (AND with existing wheres) for this execution only.
	 *
	 * @param Key|array<string, mixed>|list<mixed>|string|int|float|bool|null $identity
	 *
	 * @return array<string, mixed>|object|null
	 */
	public function fetchOne(Key|array|string|int|float|bool|null $identity = null): array|object|null
	{
		if ($identity !== null) {
			$this->applyIdentityConstraint($identity);
		}

		try {
			$handler = $this->writableHandler;
			$preparation = $this->beginFetch($handler);
			$runtime = $this->getLoadRuntime(fresh: true);
			$row = $runtime->fetchOne();

			if ($row === null) {
				return null;
			}

			$publicRow = $this->publicRow($row);

			if ($this->resultClass === null) {
				$materialized = $publicRow;
			} else {
				/** @var object $materialized */
				$materialized = map($publicRow)->to($this->resultClass);
			}

			if ($handler !== null && $preparation !== null && is_object($materialized)) {
				$handler->track($this, $preparation, [$row], [$materialized]);
			}

			return $materialized;
		} finally {
			if ($identity !== null) {
				$this->conditions->removeByTag(ConditionTag::IDENTITY);
			}
		}
	}

	/**
	 * Compile place schema + LoadGraph before LoadRuntime (proposal 0003 Phase 1).
	 *
	 * Writable prepares first (identity planning may mutate selections). When prepare
	 * already supplies a FetchPlan, reuse it; otherwise compile once locally.
	 */
	private function beginFetch(?WritableResultHandler $handler): ?WritablePreparation
	{
		if ($handler !== null) {
			$preparation = $handler->prepare($this);
			$this->fetchPlan = $preparation->getFetchPlan() ?? $this->compileFetchPlan();

			return $preparation;
		}

		// Count wrappers and other derived sources have no collection place schema.
		if ($this->isDerivedSource()) {
			$this->fetchPlan = null;

			return null;
		}

		$this->fetchPlan = $this->compileFetchPlan();

		return null;
	}

	private function compileFetchPlan(): FetchPlan
	{
		return new FetchPlan(
			(new QueryRepresentationSchemaCompiler())->compile($this),
			(new LoadGraphBuilder())->fromQuery($this),
		);
	}

	/**
	 * @param Key|array<string, mixed>|list<mixed>|string|int|float|bool $identity
	 */
	private function applyIdentityConstraint(Key|array|string|int|float|bool $identity): void
	{
		if (! $this->source instanceof CollectionInterface) {
			throw new InvalidArgumentException(
				'SelectQuery::fetchOne($identity) requires a collection-root query; derived or nested query sources cannot resolve identity.',
			);
		}

		$key = $this->source->getKey($identity);
		$conditions = [];
		foreach ($key->getValues() as $fieldName => $value) {
			$conditions[] = x()->eq($this->field($fieldName), $value);
		}

		$this->conditions->replaceByTag(ConditionTag::IDENTITY, ...$conditions);
	}

	/**
	 * @return list<FieldRef>
	 */
	private function getRootPrimaryKeyFields(self $query): array
	{
		$from = $query->getFrom();

		if (! $from instanceof CollectionInterface || ! $from->hasPrimaryKey()) {
			return [];
		}

		$fields = [];

		foreach ($from->getPrimaryKey() as $fieldName) {
			$field = $query->field($fieldName);

			if (! $field instanceof FieldRef) {
				return [];
			}

			$fields[] = $field;
		}

		return $fields;
	}

	/**
	 * @return iterable<array<string, mixed>|object>
	 */
	public function iterate(): iterable
	{
		if ($this->writableHandler !== null) {
			throw ObjectExportException::writableIterationUnsupported();
		}

		if (! $this->getRelationSelections()->isEmpty()) {
			throw RelationSelectionException::iterateNotSupported();
		}

		$rows = $this->getLoadRuntime()->iterate();

		if ($this->resultClass === null) {
			return $this->publicIterable($rows);
		}

		return $this->mapPublicRows($rows, $this->resultClass);
	}

	private function getLoadRuntime(bool $fresh = false): Relation\LoadRuntime
	{
		if ($fresh) {
			$this->runtime = null;
		}

		if ($this->runtime !== null) {
			return $this->runtime;
		}

		$executor = $this->executor ?? throw QueryNotExecutableException::forQuery($this);

		return $this->runtime = new Relation\LoadRuntime($this, $executor, $this->fetchPlan);
	}

	/**
	 * @param iterable<array<string, mixed>> $rows
	 *
	 * @return iterable<array<string, mixed>>
	 */
	private function publicIterable(iterable $rows): iterable
	{
		foreach ($rows as $row) {
			yield $this->publicRow($row);
		}
	}

	public function related(CollectionInterface $collection): self
	{
		return new self($collection, $this->executor);
	}

	/**
	 * Build a correlated query over a relation target for EXISTS / NOT EXISTS predicates.
	 *
	 * Does not select, load, or join the relation onto this parent query.
	 *
	 * @param null|callable(SelectQuery): mixed $build
	 */
	public function relatedQuery(
		RelationRef $relation,
		?callable $build = null,
	): SelectQuery {
		$target = (new RelationQueryPlanner())->plan($relation, $this);

		if ($build !== null) {
			$build($target);
		}

		return $target;
	}

	public function exposesField(string $name): bool
	{
		return DerivedOutputColumns::exposes($this, $name);
	}

	public function isDerivedSource(): bool
	{
		return $this->source instanceof DerivedSelectQuery;
	}

	public function canLoadRelations(): bool
	{
		return $this->source instanceof CollectionInterface;
	}

	private function normalizeValueExpression(ValueExpressionInterface|SelectQuery $expression): ValueExpressionInterface
	{
		if ($expression instanceof SelectQuery) {
			return new SubqueryExpression($expression);
		}

		return $expression;
	}

	private function assertJoinNameAvailable(string $name): void
	{
		foreach ($this->joins as $join) {
			if ($join->getName() === $name) {
				throw new InvalidArgumentException(sprintf(
					'Join name "%s" is already used by this query.',
					$name,
				));
			}
		}
	}

	/**
	 * @param iterable<array<string, mixed>> $rows
	 * @param class-string $resultClass
	 *
	 * @return iterable<object>
	 */
	private function mapPublicRows(iterable $rows, string $resultClass): iterable
	{
		foreach ($rows as $row) {
			/** @var object $mapped */
			$mapped = map($this->publicRow($row))->to($resultClass);

			yield $mapped;
		}
	}

	/**
	 * @param array<string, mixed> $row
	 *
	 * @return array<string, mixed>
	 */
	private function publicRow(array $row): array
	{
		$public = $row;

		foreach ($this->selections->getByTag(SelectionTag::INTERNAL) as $selection) {
			unset($public[$selection->getSelectionKey()]);
		}

		return $this->stripInternalKeys($public);
	}

	/**
	 * @param array<string, mixed> $row
	 *
	 * @return array<string, mixed>
	 */
	private function stripInternalKeys(array $row): array
	{
		$public = [];

		foreach ($row as $key => $value) {
			if (is_string($key) && (
				str_starts_with($key, '_od_internal_')
				|| str_starts_with($key, '__on_data_')
			)) {
				continue;
			}

			if (is_array($value)) {
				if ($value !== [] && array_is_list($value)) {
					$public[$key] = array_map(
						function (mixed $item): mixed {
							return is_array($item) ? $this->stripInternalKeys($item) : $item;
						},
						$value,
					);

					continue;
				}

				$public[$key] = $this->stripInternalKeys($value);

				continue;
			}

			$public[$key] = $value;
		}

		return $public;
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 *
	 * @return list<array<string, mixed>>
	 */
	private function publicRows(array $rows): array
	{
		$publicRows = [];

		foreach ($rows as $row) {
			$publicRows[] = $this->publicRow($row);
		}

		return $publicRows;
	}

	private function describeSource(QuerySourceInterface $source): string
	{
		$path = $source->getPath();

		return $path === []
			? $this->getCollection()->getName()
			: implode('.', $path);
	}

	private function buildRelationSelections(): RelationSelectionTree
	{
		$tree = new RelationSelectionTree();

		foreach ($this->relationRefs as $relation) {
			$this->collectRelationSelections($relation, $tree);
		}

		return $tree;
	}

	private function assertNoRelationSelectionCollisions(?RelationSelectionTree $relationSelections = null): void
	{
		$relationSelections ??= $this->buildRelationSelections();
		if ($relationSelections->isEmpty()) {
			return;
		}

		$rootRelationNames = [];

		foreach ($relationSelections->getAll() as $relation) {
			if ($relation->getParentPathKey() !== null) {
				continue;
			}

			$rootRelationNames[$relation->getName()] = true;
		}

		foreach ($this->selections->getExplicit() as $selection) {
			$expression = $selection->getExpression();

			if (! $expression instanceof AliasedExpression) {
				continue;
			}

			if (isset($rootRelationNames[$expression->getAlias()])) {
				throw RelationSelectionException::rootAliasCollision($expression->getAlias());
			}
		}

		foreach ($relationSelections->getAll() as $relation) {
			$this->assertNoLevelSelectionCollision($relation);
		}
	}

	/**
	 * Extends the root check above to every selected relation level: an
	 * explicit alias/path at that level cannot collide with one of its own
	 * selected child relation names.
	 */
	private function assertNoLevelSelectionCollision(RelationSelection $relation): void
	{
		$relationRef = $relation->getRelationRef();
		$childNames = [];

		foreach ($relationRef->getRelationRefs() as $child) {
			if ($child->isSelected()) {
				$childNames[$child->getName()] = true;
			}
		}

		if ($childNames === []) {
			return;
		}

		foreach ($relationRef->getSelections()->getExplicit() as $selection) {
			$expression = $selection->getExpression();

			if (! $expression instanceof AliasedExpression) {
				continue;
			}

			if (isset($childNames[$expression->getAlias()])) {
				throw RelationSelectionException::levelAliasCollision($relationRef->getPath(), $expression->getAlias());
			}
		}
	}

	private function collectRelationSelections(RelationRef $relation, RelationSelectionTree $tree): void
	{
		if ($relation->isSelected()) {
			$tree->add($relation);
		}

		foreach ($relation->getRelationRefs() as $child) {
			$this->collectRelationSelections($child, $tree);
		}
	}
}
