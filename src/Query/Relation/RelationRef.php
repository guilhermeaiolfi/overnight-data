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
use ON\Data\Query\Expression\FieldRef;
use ON\Data\Query\QuerySourceInterface;
use ON\Data\Query\Relation\Loader\LoaderInterface;
use ON\Data\Query\SelectQuery;
use ON\Data\Query\Sort\Sort;
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

	/**
	 * @var ?list<string>
	 */
	private ?array $fields = null;

	/**
	 * @var list<ConditionInterface>
	 */
	private array $conditions = [];

	/**
	 * @var list<Sort>
	 */
	private array $sorts = [];

	private ?LoadStrategy $strategy = null;

	public function __construct(
		private readonly SelectQuery $query,
		private readonly RelationInterface $relation,
		private readonly ?self $parentRelation = null,
	) {
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

	public function getFields(): ?array
	{
		return $this->fields;
	}

	/**
	 * @return list<ConditionInterface>
	 */
	public function getConditions(): array
	{
		return $this->conditions;
	}

	/**
	 * @return list<Sort>
	 */
	public function getSorts(): array
	{
		return $this->sorts;
	}

	public function getStrategy(): ?LoadStrategy
	{
		return $this->strategy;
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

	/**
	 * @return list<self>
	 */
	public function getRelationRefs(): array
	{
		return array_values($this->relationRefs);
	}

	public function fields(string|FieldRef|array ...$fields): self
	{
		$this->assertSelectable();
		$this->fields = $this->normalizeSelectionFields($this->normalizeFieldArguments($fields));
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
	 * @param list<mixed> $fields
	 * @return list<string>
	 */
	private function normalizeSelectionFields(array $fields): array
	{
		if ($fields === []) {
			throw RelationSelectionException::emptyRelationFields($this->getPath());
		}

		$normalized = [];
		$seen = [];

		foreach ($fields as $field) {
			$fieldName = $this->normalizeFieldSelectionValue($field);

			if (trim($fieldName) === '') {
				throw RelationSelectionException::invalidRelationFieldName($this->getPath(), $fieldName);
			}

			if (! $this->getCollection()->hasField($fieldName)) {
				throw RelationSelectionException::unknownRelationField($this->getPath(), $fieldName);
			}

			$field = $this->getCollection()->getField($fieldName);

			$canonicalName = $field->getName();

			if (isset($seen[$canonicalName])) {
				continue;
			}

			$seen[$canonicalName] = true;
			$normalized[] = $canonicalName;
		}

		return $normalized;
	}

	/**
	 * @param list<string|FieldRef|array<mixed>> $fields
	 * @return list<string|FieldRef>
	 */
	private function normalizeFieldArguments(array $fields): array
	{
		$normalized = [];

		foreach ($fields as $field) {
			if (is_array($field)) {
				if (! array_is_list($field)) {
					throw RelationSelectionException::invalidRelationFieldsType($this->getPath());
				}

				array_push($normalized, ...$field);

				continue;
			}

			$normalized[] = $field;
		}

		return $normalized;
	}

	private function normalizeFieldSelectionValue(mixed $field): string
	{
		if (is_string($field)) {
			return $field;
		}

		if (! $field instanceof FieldRef) {
			throw RelationSelectionException::invalidRelationFieldName($this->getPath(), $field);
		}

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
