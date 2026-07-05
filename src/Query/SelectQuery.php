<?php

declare(strict_types=1);

namespace ON\Data\Query;

use InvalidArgumentException;
use ON\Data\Database\Exception\QueryNotExecutableException;
use ON\Data\Database\QueryExecutorInterface;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Field\FieldInterface;
use ON\Data\Definition\Relation\RelationInterface;
use ON\Data\ORM\Query\MutableQueryResultTracker;
use ON\Data\ORM\Session;
use ON\Data\Query\Condition\ConditionInterface;
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
use ON\Data\Query\Relation\RelationRef;
use ON\Data\Query\Relation\RelationSelectionTree;
use ON\Data\Query\Result\ObjectResultMaterializer;
use ON\Data\Query\Selection\SelectionList;
use ON\Data\Query\Sort\Sort;
use stdClass;

final class SelectQuery implements QuerySourceInterface
{
	private static int $nextAutoAlias = 0;

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

	/**
	 * @var list<ConditionInterface>
	 */
	private array $conditions = [];

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

	/**
	 * @var array<string, SourceFieldExpression>
	 */
	private array $projectedFieldRefs = [];

	private ?string $alias = null;

	private ?StarExpression $sourceStar = null;

	private ?string $resultClass = null;

	private bool $mutable = false;

	private ?Session $session = null;

	public function __construct(
		private readonly CollectionInterface|SelectQuery $source,
		private ?QueryExecutorInterface $executor = null,
	) {
		if ($source instanceof self && ! $source->hasAlias()) {
			throw new InvalidArgumentException('SelectQuery sources must be aliased with as() before they are used in FROM.');
		}

		$this->selections = new SelectionList();
	}

	public function getQuery(): SelectQuery
	{
		return $this;
	}

	public function getCollection(): CollectionInterface
	{
		if ($this->hasAlias()) {
			throw new InvalidArgumentException('Derived query sources do not expose collection metadata.');
		}

		if ($this->source instanceof CollectionInterface) {
			return $this->source;
		}

		return $this->source->getCollection();
	}

	public function getFrom(): CollectionInterface|SelectQuery
	{
		return $this->source;
	}

	public function getSourceName(): string
	{
		if ($this->hasAlias()) {
			return $this->alias;
		}

		if ($this->source instanceof CollectionInterface) {
			return $this->source->getName();
		}

		return $this->source->getAlias() ?? 'derived query';
	}

	public function getPath(): array
	{
		if ($this->hasAlias()) {
			return [$this->alias];
		}

		return [];
	}

	public function getAlias(): ?string
	{
		return $this->alias;
	}

	public function requireAlias(): string
	{
		if ($this->alias === null) {
			throw new InvalidArgumentException('SelectQuery does not have an alias.');
		}

		return $this->alias;
	}

	public function hasAlias(): bool
	{
		return $this->alias !== null;
	}

	public function isExecutable(): bool
	{
		return ! $this->hasAlias() && $this->source instanceof CollectionInterface && $this->executor instanceof QueryExecutorInterface;
	}

	public function detach(): self
	{
		$this->executor = null;

		return $this;
	}

	public function field(string $name): FieldRef|SourceFieldExpression
	{
		$name = trim($name);

		if ($name === '') {
			throw new InvalidArgumentException('SelectQuery::field() requires a non-empty field name.');
		}

		if ($this->hasAlias()) {
			if ($this->selections->hasSelectionKey($name)) {
				return $this->projectedFieldRefs[$name] ??= new SourceFieldExpression($this, $name);
			}

			throw UnknownQueryFieldException::forDefinition($name, $this->getSourceName());
		}

		if ($this->source instanceof SelectQuery) {
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

	public function __get(string $name): FieldRef|RelationRef
	{
		if (! $this->canLoadRelations()) {
			throw new InvalidArgumentException('Derived query sources do not support magic member access; use field() for selected fields.');
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
		if ($this->hasAlias()) {
			return $this->sourceStar ??= new StarExpression($this);
		}

		return $this->star ??= new StarExpression($this);
	}

	public function all(): StarExpression
	{
		return $this->star();
	}

	public function as(?string $alias = null): self
	{
		if ($alias !== null && trim($alias) === '') {
			throw new InvalidArgumentException('Derived query source aliases cannot be empty.');
		}

		$this->alias = $alias === null ? $this->generateAutoAlias() : trim($alias);

		return $this;
	}

	public function copy(): self
	{
		$copy = new self($this->source, $this->executor);

		foreach ($this->selections->getAll() as $selection) {
			$copy->selections->add(
				$this->copySelectionExpression($selection->getExpression(), $copy),
				$selection->getTags(),
				$selection->isExplicit(),
			);
		}

		$copy->conditions = array_map(
			fn (ConditionInterface $condition): ConditionInterface => $condition->bindTo($copy, from: $this),
			$this->conditions,
		);
		$copy->groups = array_map(
			fn (ValueExpressionInterface $group): ValueExpressionInterface => $group->bindTo($copy, from: $this),
			$this->groups,
		);
		$copy->havingConditions = array_map(
			fn (ConditionInterface $condition): ConditionInterface => $condition->bindTo($copy, from: $this),
			$this->havingConditions,
		);
		$copy->sorts = array_map(
			fn (Sort $sort): Sort => $sort->bindTo($copy, from: $this),
			$this->sorts,
		);
		$copy->limit = $this->limit;
		$copy->offset = $this->offset;
		$copy->resultClass = $this->resultClass;
		$copy->mutable = $this->mutable;
		$copy->session = $this->session;

		$joinMap = [spl_object_id($this) => $copy];

		foreach ($this->joins as $join) {
			$source = $join->getSource();
			$copiedSource = $source instanceof Join
				? $joinMap[spl_object_id($source)] ?? $copy
				: $copy;
			$copiedJoin = new Join($copy, $copiedSource, $join->getCollection(), $join->getType(), $join->getName());

			foreach ($join->getConditions() as $condition) {
				$copiedJoin->on(...$this->rebindConditions($condition, $copy, $joinMap, $join));
			}

			$copy->joins[] = $copiedJoin;
			$joinMap[spl_object_id($join)] = $copiedJoin;
		}

		foreach ($this->relationRefs as $name => $relationRef) {
			$copy->relationRefs[$name] = $this->copyRelationRef($relationRef, $copy, null, $joinMap);
		}

		$copy->alias = $this->alias;

		return $copy;
	}

	public function select(ValueExpressionInterface|AliasedExpression|StarExpression|SelectQuery ...$expressions): self
	{
		if ($expressions === []) {
			throw new InvalidArgumentException('SelectQuery::select() requires at least one expression.');
		}

		$normalized = [];

		foreach ($expressions as $expression) {
			$normalized[] = $expression instanceof SelectQuery
				? new SubqueryExpression($expression)
				: $expression;
		}

		$this->selections->addExplicit($normalized);

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

		array_push($this->conditions, ...$conditions);

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

	public function bindConditions(QuerySourceInterface $from, ConditionInterface ...$conditions): self
	{
		if ($conditions === []) {
			throw new InvalidArgumentException('SelectQuery::bindConditions() requires at least one condition.');
		}

		foreach ($conditions as $condition) {
			$this->where($condition->bindTo($this, from: $from));
		}

		return $this;
	}

	public function bindSorts(QuerySourceInterface $from, Sort ...$sorts): self
	{
		if ($sorts === []) {
			throw new InvalidArgumentException('SelectQuery::bindSorts() requires at least one sort.');
		}

		foreach ($sorts as $sort) {
			$this->orderBy($sort->bindTo($this, from: $from));
		}

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
		if ($class !== stdClass::class) {
			throw ObjectExportException::unsupportedClass($class);
		}

		$this->resultClass = $class;

		return $this;
	}

	public function getResultClass(): ?string
	{
		return $this->resultClass;
	}

	public function mutable(Session $session): self
	{
		if ($this->resultClass === null) {
			throw ObjectExportException::requiresObjectExport();
		}

		$this->mutable = true;
		$this->session = $session;

		return $this;
	}

	public function isMutable(): bool
	{
		return $this->mutable;
	}

	public function getSession(): ?Session
	{
		return $this->session;
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
	 * @return list<array<string, mixed>>|list<stdClass>
	 */
	public function fetchAll(): array
	{
		$executor = $this->requireExecutor();
		$relationSelections = $this->getRelationSelections();

		if ($relationSelections->isEmpty()) {
			$rows = $executor->fetchAll($this);
		} else {
			$rows = (new Relation\LoadRuntime($this, $executor))->fetchAll();
		}

		$rows = $this->materializeRows($rows);

		if ($this->mutable) {
			$this->trackMutableResults($rows);
		}

		return $rows;
	}

	/**
	 * @return array<string, mixed>|stdClass|null
	 */
	public function fetchOne(): array|object|null
	{
		$executor = $this->requireExecutor();
		$relationSelections = $this->getRelationSelections();

		if ($relationSelections->isEmpty()) {
			$row = $executor->fetchOne($this);
		} else {
			$row = (new Relation\LoadRuntime($this, $executor))->fetchOne();
		}

		if ($row === null) {
			return null;
		}

		$result = $this->materializeRow($row);

		if ($this->mutable && is_object($result)) {
			(new MutableQueryResultTracker())->trackOne($this, $this->requireMutableSession(), $result);
		}

		return $result;
	}

	/**
	 * @return iterable<array<string, mixed>>
	 */
	public function iterate(): iterable
	{
		if (! $this->getRelationSelections()->isEmpty()) {
			throw RelationSelectionException::iterateNotSupported();
		}

		return $this->requireExecutor()->iterate($this);
	}

	public function related(CollectionInterface $collection): self
	{
		return new self($collection, $this->executor);
	}

	public function exposesField(string $name): bool
	{
		return $this->selections->hasSelectionKey($name);
	}

	public function isDerivedSource(): bool
	{
		return $this->source instanceof SelectQuery;
	}

	public function canLoadRelations(): bool
	{
		return $this->source instanceof CollectionInterface && ! $this->hasAlias();
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

	private function requireExecutor(): QueryExecutorInterface
	{
		if (! $this->executor instanceof QueryExecutorInterface) {
			throw QueryNotExecutableException::forQuery($this);
		}

		return $this->executor;
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 *
	 * @return list<array<string, mixed>>|list<stdClass>
	 */
	private function materializeRows(array $rows): array
	{
		if ($this->resultClass === null) {
			return $rows;
		}

		return (new ObjectResultMaterializer())->materializeAll($rows, $this->resultClass);
	}

	/**
	 * @param array<string, mixed> $row
	 *
	 * @return array<string, mixed>|stdClass
	 */
	private function materializeRow(array $row): array|object
	{
		if ($this->resultClass === null) {
			return $row;
		}

		return (new ObjectResultMaterializer())->materialize($row, $this->resultClass);
	}

	/**
	 * @param list<object> $objects
	 */
	private function trackMutableResults(array $objects): void
	{
		(new MutableQueryResultTracker())->trackAll($this, $this->requireMutableSession(), $objects);
	}

	private function requireMutableSession(): Session
	{
		if (! $this->session instanceof Session) {
			throw ObjectExportException::requiresMutableSession();
		}

		return $this->session;
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

	private function generateAutoAlias(): string
	{
		return 'd' . self::$nextAutoAlias++;
	}

	private function copySelectionExpression(
		ValueExpressionInterface|AliasedExpression|StarExpression $expression,
		self $copy,
	): ValueExpressionInterface|AliasedExpression|StarExpression {
		if ($expression instanceof AliasedExpression) {
			$inner = $expression->getExpression();

			if ($inner instanceof StarExpression) {
				return $inner->bindTo($copy, from: $this);
			}

			return $inner->bindTo($copy, from: $this)->as($expression->getAlias());
		}

		return $expression->bindTo($copy, from: $this);
	}

	private function copyRelationRef(
		RelationRef $relationRef,
		self $copy,
		?RelationRef $parent,
		array $joinMap,
	): RelationRef {
		$copied = new RelationRef($copy, $relationRef->getDefinition(), $parent);

		if ($relationRef->getFields() !== null) {
			$copied->fields($relationRef->getFields());
		} elseif ($relationRef->isSelected()) {
			$copied->load();
		}

		$copied->visible($relationRef->isVisible());

		if ($relationRef->getConditions() !== []) {
			$copied->where(...array_map(
				fn (ConditionInterface $condition): ConditionInterface => $this->rebindCondition($condition, $copy, $joinMap),
				$relationRef->getConditions(),
			));
		}

		if ($relationRef->getSorts() !== []) {
			$copied->orderBy(...array_map(
				fn (Sort $sort): Sort => $this->rebindSort($sort, $copy, $joinMap),
				$relationRef->getSorts(),
			));
		}

		if ($relationRef->getStrategy() !== null) {
			$copied->strategy($relationRef->getStrategy());
		}

		if ($relationRef->getLimit() !== null) {
			$copied->limit($relationRef->getLimit());
		}

		if ($relationRef->hasOffset()) {
			$copied->offset($relationRef->getOffset());
		}

		foreach ($relationRef->getRelationRefs() as $child) {
			$copied->relation($child->getName());
			$copy->relationRefs[$relationRef->getName()] = $copied;
			$this->copyRelationRef($child, $copy, $copied, $joinMap);
		}

		return $copied;
	}

	/**
	 * @return list<ConditionInterface>
	 */
	private function rebindConditions(
		ConditionInterface $condition,
		self $copy,
		array $joinMap,
		Join $join,
	): array {
		return [$this->rebindCondition($condition, $copy, [spl_object_id($this) => $copy, spl_object_id($join) => $joinMap[spl_object_id($join)] ?? $copy] + $joinMap)];
	}

	private function rebindCondition(ConditionInterface $condition, self $copy, array $sourceMap): ConditionInterface
	{
		$rebound = $condition->bindTo($copy, from: $this);

		foreach ($sourceMap as $sourceId => $target) {
			if ($sourceId === spl_object_id($this) || ! $target instanceof QuerySourceInterface) {
				continue;
			}

			foreach ($this->joins as $join) {
				if (spl_object_id($join) !== $sourceId) {
					continue;
				}

				$rebound = $rebound->bindTo($target, from: $join);
			}
		}

		return $rebound;
	}

	private function rebindSort(Sort $sort, self $copy, array $sourceMap): Sort
	{
		$rebound = $sort->bindTo($copy, from: $this);

		foreach ($sourceMap as $sourceId => $target) {
			if ($sourceId === spl_object_id($this) || ! $target instanceof QuerySourceInterface) {
				continue;
			}

			foreach ($this->joins as $join) {
				if (spl_object_id($join) !== $sourceId) {
					continue;
				}

				$rebound = $rebound->bindTo($target, from: $join);
			}
		}

		return $rebound;
	}
}
