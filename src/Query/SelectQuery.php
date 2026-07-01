<?php

declare(strict_types=1);

namespace ON\Data\Query;

use InvalidArgumentException;
use ON\Data\Database\Exception\QueryNotExecutableException;
use ON\Data\Database\QueryExecutorInterface;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Field\FieldInterface;
use ON\Data\Definition\Relation\RelationInterface;
use ON\Data\Query\Condition\ConditionInterface;
use ON\Data\Query\Exception\RelationSelectionException;
use ON\Data\Query\Exception\UnknownQueryExpressionException;
use ON\Data\Query\Exception\UnknownQueryFieldException;
use ON\Data\Query\Exception\UnknownQueryMemberException;
use ON\Data\Query\Exception\UnknownQueryRelationException;
use ON\Data\Query\Expression\AliasedExpression;
use ON\Data\Query\Expression\FieldRef;
use ON\Data\Query\Expression\StarExpression;
use ON\Data\Query\Expression\SubqueryExpression;
use ON\Data\Query\Expression\ValueExpressionInterface;
use ON\Data\Query\Relation\RelationRef;
use ON\Data\Query\Relation\RelationSelectionTree;
use ON\Data\Query\Selection\SelectionList;
use ON\Data\Query\Sort\Sort;

final class SelectQuery implements QuerySourceInterface
{
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

	private readonly RelationSelectionTree $relationSelections;

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

	public function __construct(
		private readonly CollectionInterface $collection,
		private ?QueryExecutorInterface $executor = null,
	) {
		$this->selections = new SelectionList();
		$this->relationSelections = new RelationSelectionTree();
	}

	public function getQuery(): SelectQuery
	{
		return $this;
	}

	public function getCollection(): CollectionInterface
	{
		return $this->collection;
	}

	public function getPath(): array
	{
		return [];
	}

	public function isExecutable(): bool
	{
		return $this->executor instanceof QueryExecutorInterface;
	}

	public function detach(): self
	{
		$this->executor = null;

		return $this;
	}

	public function field(string $name): FieldRef
	{
		if (isset($this->fieldRefs[$name])) {
			return $this->fieldRefs[$name];
		}

		$field = $this->collection->getField($name);

		if (! $field instanceof FieldInterface) {
			throw UnknownQueryFieldException::forDefinition($name, $this->collection->getName());
		}

		return $this->fieldRefs[$name] = new FieldRef($this, $field);
	}

	public function relation(string $name): RelationRef
	{
		if (isset($this->relationRefs[$name])) {
			return $this->relationRefs[$name];
		}

		$relation = $this->collection->getRelation($name);

		if (! $relation instanceof RelationInterface) {
			throw UnknownQueryRelationException::forDefinition($name, $this->collection->getName());
		}

		return $this->relationRefs[$name] = new RelationRef($this, $relation);
	}

	public function __get(string $name): FieldRef|RelationRef
	{
		if ($this->collection->hasField($name)) {
			return $this->field($name);
		}

		if ($this->collection->hasRelation($name)) {
			return $this->relation($name);
		}

		throw UnknownQueryMemberException::forDefinition($name, $this->collection->getName());
	}

	public function star(): StarExpression
	{
		return $this->star ??= new StarExpression($this);
	}

	public function as(string $alias): AliasedExpression
	{
		return (new SubqueryExpression($this))->as($alias);
	}

	public function select(ValueExpressionInterface|AliasedExpression|SelectQuery|RelationRef ...$expressions): self
	{
		if ($expressions === []) {
			throw new InvalidArgumentException('SelectQuery::select() requires at least one expression.');
		}

		$normalized = [];

		foreach ($expressions as $expression) {
			if ($expression instanceof RelationRef) {
				$this->addRelationSelection($expression);

				continue;
			}

			$normalized[] = $expression instanceof SelectQuery
				? new SubqueryExpression($expression)
				: $expression;
		}

		if ($normalized !== []) {
			$this->selections->addExplicit($normalized);
		}

		$this->assertNoRelationSelectionCollisions();

		return $this;
	}

	public function load(RelationRef ...$relations): self
	{
		if ($relations === []) {
			throw new InvalidArgumentException('SelectQuery::load() requires at least one relation.');
		}

		foreach ($relations as $relation) {
			$this->addRelationSelection($relation);
		}

		$this->assertNoRelationSelectionCollisions();

		return $this;
	}

	public function require(FieldRef|ValueExpressionInterface|AliasedExpression|SelectQuery $field, string $reason): self
	{
		$expression = $field instanceof SelectQuery
			? new SubqueryExpression($field)
			: $field;

		$this->selections->add($expression, $reason);

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

	public function getSelections(): SelectionList
	{
		return $this->selections;
	}

	public function getRelationSelections(): RelationSelectionTree
	{
		return $this->relationSelections;
	}

	public function get(string $name): ValueExpressionInterface
	{
		$name = trim($name);

		if ($name === '') {
			throw new InvalidArgumentException('SelectQuery::get() requires a non-empty expression name.');
		}

		if (! $this->selections->hasNamedExpression($name)) {
			throw UnknownQueryExpressionException::forQuery($name, $this->collection->getName());
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
	 * @return list<array<string, mixed>>
	 */
	public function fetchAll(): array
	{
		$executor = $this->requireExecutor();

		if ($this->relationSelections->isEmpty()) {
			return $executor->fetchAll($this);
		}

		return (new Relation\LoadRuntime($this, $executor))->fetchAll();
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function fetchOne(): ?array
	{
		$executor = $this->requireExecutor();

		if ($this->relationSelections->isEmpty()) {
			return $executor->fetchOne($this);
		}

		return (new Relation\LoadRuntime($this, $executor))->fetchOne();
	}

	/**
	 * @return iterable<array<string, mixed>>
	 */
	public function iterate(): iterable
	{
		if (! $this->relationSelections->isEmpty()) {
			throw RelationSelectionException::iterateNotSupported();
		}

		return $this->requireExecutor()->iterate($this);
	}

	public function related(CollectionInterface $collection): self
	{
		return new self($collection, $this->executor);
	}

	private function normalizeValueExpression(ValueExpressionInterface|SelectQuery $expression): ValueExpressionInterface
	{
		if ($expression instanceof SelectQuery) {
			return new SubqueryExpression($expression);
		}

		return $expression;
	}

	private function addRelationSelection(RelationRef $relation): void
	{
		if ($relation->getQuery() !== $this) {
			throw RelationSelectionException::foreignQueryRelation($relation, $this);
		}

		$this->relationSelections->add($relation);
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

	private function describeSource(QuerySourceInterface $source): string
	{
		$path = $source->getPath();

		return $path === []
			? $source->getCollection()->getName()
			: implode('.', $path);
	}

	private function assertNoRelationSelectionCollisions(): void
	{
		if ($this->relationSelections->isEmpty()) {
			return;
		}

		$rootRelationNames = [];

		foreach ($this->relationSelections->getAll() as $relation) {
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
}
