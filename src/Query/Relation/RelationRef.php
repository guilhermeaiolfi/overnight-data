<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation;

use ArgumentCountError;
use InvalidArgumentException;
use LogicException;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Field\FieldInterface;
use ON\Data\Definition\Relation\RelationInterface;
use ON\Data\Query\Condition\ConditionInterface;
use ON\Data\Query\Exception\RelationLoaderException;
use ON\Data\Query\Exception\RelationSelectionException;
use ON\Data\Query\Exception\UnknownQueryFieldException;
use ON\Data\Query\Exception\UnknownQueryMemberException;
use ON\Data\Query\Exception\UnknownQueryRelationException;
use ON\Data\Query\Expression\AliasedExpression;
use ON\Data\Query\Expression\FieldRef;
use ON\Data\Query\Expression\StarExpression;
use ON\Data\Query\Expression\ValueExpressionInterface;
use ON\Data\Query\QuerySourceInterface;
use ON\Data\Query\Relation\Loader\LoaderInterface;
use ON\Data\Query\Selection\SelectionList;
use ON\Data\Query\Selection\SelectionTag;
use ON\Data\Query\SelectQuery;
use ON\Data\Query\Sort\Sort;
use ON\Data\Query\SourceMap;
use ReflectionClass;
use ReflectionException;

final class RelationRef implements QuerySourceInterface
{
	/**
	 * @var array<string, FieldRef>
	 */
	private array $fieldRefs = [];

	/**
	 * @var array<string, self>
	 */
	private array $relationRefs = [];

	private ?LoaderInterface $loader = null;

	private ?QuerySourceInterface $joinedSource = null;

	private bool $selected = false;

	private bool $visible = true;

	private readonly SelectionList $selections;

	/**
	 * @var list<ConditionInterface>
	 */
	private array $conditions = [];

	/**
	 * @var list<Sort>
	 */
	private array $sorts = [];

	private ?int $limit = null;

	private ?int $offset = null;

	private ?LoadStrategy $strategy = null;

	public function __construct(
		private readonly SelectQuery $query,
		private readonly RelationInterface $relation,
		private readonly ?self $parentRelation = null,
	) {
		$this->selections = new SelectionList();
		$this->selections->add($this->all(), [SelectionTag::DEFAULT, SelectionTag::EXPLICIT]);
	}

	public function getQuery(): SelectQuery
	{
		return $this->query;
	}

	public function getDefinition(): RelationInterface
	{
		return $this->relation;
	}

	public function getParentRelation(): ?self
	{
		return $this->parentRelation;
	}

	public function isLoaded(): bool
	{
		return $this->selected;
	}

	public function isSelected(): bool
	{
		return $this->selected;
	}

	public function isVisible(): bool
	{
		return $this->visible;
	}

	public function getSelections(): SelectionList
	{
		return $this->selections;
	}

	public function hasDefaultSelection(): bool
	{
		return $this->selections->getByTag(SelectionTag::DEFAULT) !== [];
	}

	/**
	 * @return list<ConditionInterface>
	 */
	public function getConditions(): array
	{
		return $this->conditions;
	}

	/**
	 * @param list<ConditionInterface> $conditions
	 */
	public function setConditions(array $conditions): self
	{
		$this->conditions = array_values($conditions);

		return $this;
	}

	/**
	 * @return list<Sort>
	 */
	public function getSorts(): array
	{
		return $this->sorts;
	}

	/**
	 * @param list<Sort> $sorts
	 */
	public function setSorts(array $sorts): self
	{
		$this->sorts = array_values($sorts);

		return $this;
	}

	public function getStrategy(): ?LoadStrategy
	{
		return $this->strategy;
	}

	public function getLimit(): ?int
	{
		return $this->limit;
	}

	public function getOffset(): int
	{
		return $this->offset ?? 0;
	}

	public function hasOffset(): bool
	{
		return $this->offset !== null;
	}

	public function getParentSource(): QuerySourceInterface
	{
		return $this->parentRelation?->getJoinedSource()
			?? $this->query;
	}

	public function getJoinedSource(): QuerySourceInterface
	{
		return $this->joinedSource ??= $this->getLoader()->join($this);
	}

	public function hasJoinedSource(): bool
	{
		return $this->joinedSource !== null;
	}

	/**
	 * Install an already materialized relation join on this branch.
	 *
	 * Separate from {@see getJoinedSource()}: copying must attach the mapped
	 * join without asking a loader to create another one.
	 */
	public function setJoinedSource(QuerySourceInterface $source): void
	{
		if ($this->joinedSource !== null && $this->joinedSource !== $source) {
			throw new LogicException('A relation reference cannot set two joined sources.');
		}

		$this->joinedSource = $source;
	}

	public function rebind(SourceMap $sources): self
	{
		$target = $sources->remap($this);

		if (! $target instanceof self) {
			throw new LogicException(sprintf(
				'RelationRef::rebind() requires a RelationRef counterpart, got %s.',
				$target::class,
			));
		}

		if ($this->hasJoinedSource()) {
			$joinedSource = $sources->remap($this->getJoinedSource());
			$target->setJoinedSource($joinedSource);
		}

		$target->visible($this->visible);

		$target->selections->clear();
		$target->selections->merge($this->selections->projectTo($sources));
		$target->selected = $this->selected;

		$target->setConditions(array_map(
			static fn (ConditionInterface $condition): ConditionInterface => $condition->rebind($sources),
			$this->conditions,
		));
		$target->setSorts(array_map(
			static fn (Sort $sort): Sort => $sort->rebind($sources),
			$this->sorts
		));

		if ($this->strategy !== null) {
			$target->strategy($this->strategy);
		}

		if ($this->limit !== null) {
			$target->limit($this->limit);
		}

		if ($this->offset !== null) {
			$target->offset($this->offset);
		}

		foreach ($this->relationRefs as $relation) {
			$relation->rebind($sources);
		}

		return $target;
	}

	public function getCollection(): CollectionInterface
	{
		return $this->getDefinition()->getCollection();
	}

	public function getName(): string
	{
		return $this->getDefinition()->getName();
	}

	/**
	 * @return list<string>
	 */
	public function getPath(): array
	{
		$path = $this->parentRelation?->getPath() ?? [];
		$path[] = $this->getName();

		return $path;
	}

	/**
	 * True when this relation is a strict nested path under $ancestor on the same query.
	 * A root {@see SelectQuery} (empty path) matches any relation on that query.
	 */
	public function isUnder(QuerySourceInterface $ancestor): bool
	{
		if ($this->getQuery() !== $ancestor->getQuery()) {
			return false;
		}

		$ancestorPath = $ancestor->getPath();
		$path = $this->getPath();

		if (count($path) <= count($ancestorPath)) {
			return false;
		}

		return array_slice($path, 0, count($ancestorPath)) === $ancestorPath;
	}

	/**
	 * Path segments of this relation relative to $ancestor (requires {@see isUnder()}).
	 *
	 * @return list<string>
	 */
	public function relativeTo(QuerySourceInterface $ancestor): array
	{
		return array_values(array_slice($this->getPath(), count($ancestor->getPath())));
	}

	public function field(string $name): FieldRef
	{
		if (isset($this->fieldRefs[$name])) {
			return $this->fieldRefs[$name];
		}

		$field = $this->getCollection()->getField($name);

		if (! $field instanceof FieldInterface) {
			throw UnknownQueryFieldException::forDefinition($name, $this->getCollection()->getName());
		}

		return $this->fieldRefs[$name] = new FieldRef($this, $field);
	}

	public function relation(string $name): self
	{
		if (isset($this->relationRefs[$name])) {
			return $this->relationRefs[$name];
		}

		$relation = $this->getCollection()->getRelation($name);

		if (! $relation instanceof RelationInterface) {
			throw UnknownQueryRelationException::forDefinition($name, $this->getCollection()->getName());
		}

		return $this->relationRefs[$name] = new self($this->query, $relation, $this);
	}

	public function all(): StarExpression
	{
		return $this->star();
	}

	public function star(): StarExpression
	{
		return new StarExpression($this);
	}

	/**
	 * @return list<self>
	 */
	public function getRelationRefs(): array
	{
		return array_values($this->relationRefs);
	}

	/**
	 * Select this level's own visible/star projection, aliases, or child
	 * relations — mirrors {@see SelectQuery::select()} for a nested level.
	 *
	 * String field names (and list arrays of names) are identity-aliased own
	 * fields at this level — the short form for everyday projections:
	 * `select('id', 'title')` ≡ `select($this->id, $this->title)`.
	 *
	 * Selecting a child relation only marks it loaded/visible; it does not
	 * clear this level's default field selection (traversal-only intent),
	 * matching {@see SelectQuery::select()}'s handling of bare RelationRefs.
	 * Any scalar/value argument replaces this level's scalar projection.
	 *
	 * @param string|list<string|FieldRef>|ValueExpressionInterface|AliasedExpression|StarExpression|self ...$expressions
	 */
	public function select(string|array|ValueExpressionInterface|AliasedExpression|StarExpression|self ...$expressions): self
	{
		$this->assertSelectable();

		if ($expressions === []) {
			throw RelationSelectionException::emptyRelationSelection($this->getPath());
		}

		$normalized = [];
		$seenOwnFields = [];

		foreach ($this->expandSelectArguments($expressions) as $expression) {
			if ($expression instanceof self) {
				if ($expression->getQuery() !== $this->query) {
					throw RelationSelectionException::foreignQueryRelation($expression, $this->query);
				}

				if (! $expression->isSelected()) {
					$expression->load();
				}

				continue;
			}

			if (is_string($expression)) {
				$fieldName = $this->normalizeOwnFieldName($expression);

				if (isset($seenOwnFields[$fieldName])) {
					continue;
				}

				$seenOwnFields[$fieldName] = true;
				$normalized[] = $this->field($fieldName)->as($fieldName);

				continue;
			}

			$item = $this->normalizeSelectExpression($expression);

			if (
				$item instanceof AliasedExpression
				&& $item->getExpression() instanceof FieldRef
				&& $item->getExpression()->getSource() === $this
				&& $item->getExpression()->getField()->getName() === $item->getAlias()
			) {
				$fieldName = $item->getExpression()->getField()->getName();

				if (isset($seenOwnFields[$fieldName])) {
					continue;
				}

				$seenOwnFields[$fieldName] = true;
			}

			$normalized[] = $item;
		}

		if ($normalized !== []) {
			$this->selections->clear();
			$this->selections->addExplicit($normalized);
		}

		$this->markSelected();

		return $this;
	}

	public function load(): self
	{
		$this->markSelected();

		return $this;
	}

	public function visible(bool $visible = true): self
	{
		if ($this->selected && ! $visible) {
			throw RelationSelectionException::hiddenLoadedRelation($this->getPath());
		}

		$this->visible = $visible;

		return $this;
	}

	public function hidden(): self
	{
		return $this->visible(false);
	}

	public function where(ConditionInterface ...$conditions): self
	{
		if ($conditions === []) {
			throw new InvalidArgumentException('RelationRef::where() requires at least one condition.');
		}

		$this->assertSelectable();

		array_push($this->conditions, ...$conditions);
		$this->selected = true;

		return $this;
	}

	public function orderBy(Sort ...$sorts): self
	{
		if ($sorts === []) {
			throw new InvalidArgumentException('RelationRef::orderBy() requires at least one sort.');
		}

		$this->assertSelectable();

		array_push($this->sorts, ...$sorts);
		$this->selected = true;

		return $this;
	}

	public function strategy(?LoadStrategy $strategy): self
	{
		if ($strategy !== null) {
			$this->assertSelectable();
			$this->selected = true;
		}

		$this->strategy = $strategy;

		return $this;
	}

	public function join(): self
	{
		return $this->strategy(LoadStrategy::JOIN);
	}

	public function separate(): self
	{
		return $this->strategy(LoadStrategy::SEPARATE_QUERY);
	}

	public function limit(int $limit): self
	{
		$this->assertSelectable();

		if ($limit < 1) {
			throw RelationSelectionException::invalidRelationLimit($this->getPath(), $limit);
		}

		$this->limit = $limit;
		$this->markSelected();

		return $this;
	}

	public function offset(int $offset): self
	{
		$this->assertSelectable();

		if ($offset < 0) {
			throw RelationSelectionException::invalidRelationOffset($this->getPath(), $offset);
		}

		$this->offset = $offset;
		$this->markSelected();

		return $this;
	}

	public function __get(string $name): FieldRef|self
	{
		$collection = $this->getCollection();

		if ($collection->hasField($name)) {
			return $this->field($name);
		}

		if ($collection->hasRelation($name)) {
			return $this->relation($name);
		}

		throw UnknownQueryMemberException::forDefinition($name, $collection->getName());
	}

	/**
	 * Clear selection/load intent without dropping join or filter configuration.
	 *
	 * Used by {@see SelectQuery::count()} so relation references remain usable in
	 * WHERE/joins while {@see SelectQuery::getRelationSelections()} stays empty.
	 *
	 * @internal
	 */
	public function clearSelection(): void
	{
		$this->selected = false;
		$this->selections->clear();
		$this->selections->add($this->all(), [SelectionTag::DEFAULT, SelectionTag::EXPLICIT]);

		foreach ($this->relationRefs as $relation) {
			$relation->clearSelection();
		}
	}

	private function markSelected(): void
	{
		$this->assertSelectable();
		$this->selected = true;
	}

	private function assertSelectable(): void
	{
		if (! $this->visible) {
			throw RelationSelectionException::hiddenLoadedRelation($this->getPath());
		}
	}

	/**
	 * Bare own-level FieldRefs get an identity alias so their selection key
	 * is the plain field name (matching root, whose empty path already makes
	 * bare FieldRef selection keys plain); every other expression (aliases,
	 * star, flat related FieldRefs) is used as-is.
	 */
	private function normalizeSelectExpression(
		ValueExpressionInterface|AliasedExpression|StarExpression $expression,
	): ValueExpressionInterface|AliasedExpression|StarExpression {
		if ($expression instanceof FieldRef && $expression->getSource() === $this) {
			return $expression->as($expression->getName());
		}

		return $expression;
	}

	/**
	 * @param list<string|array|ValueExpressionInterface|AliasedExpression|StarExpression|self> $arguments
	 * @return list<string|ValueExpressionInterface|AliasedExpression|StarExpression|self>
	 */
	private function expandSelectArguments(array $arguments): array
	{
		$expanded = [];

		foreach ($arguments as $argument) {
			if (is_array($argument)) {
				if ($argument === []) {
					throw RelationSelectionException::emptyRelationFields($this->getPath());
				}

				if (! array_is_list($argument)) {
					throw RelationSelectionException::invalidRelationFieldsType($this->getPath());
				}

				foreach ($argument as $item) {
					if (is_string($item)) {
						$expanded[] = $item;

						continue;
					}

					if ($item instanceof FieldRef) {
						$name = $this->normalizeOwnFieldReference($item);
						$expanded[] = $this->field($name)->as($name);

						continue;
					}

					throw RelationSelectionException::invalidRelationFieldName($this->getPath(), $item);
				}

				continue;
			}

			if ($argument instanceof FieldRef) {
				$expanded[] = $argument;

				continue;
			}

			$expanded[] = $argument;
		}

		return $expanded;
	}

	private function normalizeOwnFieldName(string $fieldName): string
	{
		if (trim($fieldName) === '') {
			throw RelationSelectionException::invalidRelationFieldName($this->getPath(), $fieldName);
		}

		if (! $this->getCollection()->hasField($fieldName)) {
			throw RelationSelectionException::unknownRelationField($this->getPath(), $fieldName);
		}

		return $this->getCollection()->getField($fieldName)->getName();
	}

	private function normalizeOwnFieldReference(FieldRef $field): string
	{
		$source = $field->getSource();

		if (
			! $source instanceof self
			|| $source->getQuery() !== $this->query
			|| $source->getPath() !== $this->getPath()
		) {
			throw RelationSelectionException::invalidRelationFieldReference($this->getPath(), implode('.', $field->getPath()));
		}

		return $field->getField()->getName();
	}

	public function getLoader(): LoaderInterface
	{
		if ($this->loader !== null) {
			return $this->loader;
		}

		$loader = $this->getDefinition()->getLoader();

		if (! is_a($loader, LoaderInterface::class, true)) {
			throw RelationLoaderException::invalidLoader($this, $loader);
		}

		try {
			$reflection = new ReflectionClass($loader);

			if (! $reflection->isInstantiable()) {
				throw RelationLoaderException::invalidLoaderClass($this, $loader, 'class is not instantiable.');
			}

			$constructor = $reflection->getConstructor();

			if ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0) {
				throw RelationLoaderException::invalidLoaderClass(
					$this,
					$loader,
					'constructor must not require arguments.',
				);
			}

			$instance = $reflection->newInstance();
		} catch (RelationLoaderException $exception) {
			throw $exception;
		} catch (LogicException|ReflectionException|ArgumentCountError $exception) {
			throw RelationLoaderException::invalidLoaderClass($this, $loader, $exception->getMessage());
		}

		return $this->loader = $instance;
	}
}
