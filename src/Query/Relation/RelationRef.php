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

	public function __construct(
		private readonly SelectQuery $query,
		private readonly RelationInterface $relation,
		private readonly ?self $parentRelation = null,
		private readonly bool $load = false,
		private readonly bool $visible = true,
		private readonly ?array $fields = null,
		private readonly array $conditions = [],
		private readonly array $sorts = [],
		private readonly ?LoadStrategy $strategy = null,
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
		return $this->load;
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

	public function fields(string|FieldRef|array ...$fields): self
	{
		return $this
			->load()
			->withFields($this->normalizeFieldArguments($fields));
	}

	public function visible(bool $visible = true): self
	{
		return $this->withSelectionOptions(null, $visible);
	}

	public function hidden(): self
	{
		return $this->visible(false);
	}

	public function load(bool $load = true): self
	{
		return $this->withSelectionOptions($load, null);
	}

	public function where(ConditionInterface ...$conditions): self
	{
		if ($conditions === []) {
			throw new InvalidArgumentException('RelationRef::where() requires at least one condition.');
		}

		return $this->withConditions($conditions);
	}

	public function orderBy(Sort ...$sorts): self
	{
		if ($sorts === []) {
			throw new InvalidArgumentException('RelationRef::orderBy() requires at least one sort.');
		}

		return $this->withSorts($sorts);
	}

	public function strategy(?LoadStrategy $strategy): self
	{
		if ($strategy === $this->strategy) {
			return $this;
		}

		return new self(
			$this->query,
			$this->relation,
			$this->parentRelation,
			$this->load,
			$this->visible,
			$this->fields,
			$this->conditions,
			$this->sorts,
			$strategy,
		);
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

	private function withSelectionOptions(?bool $load = null, ?bool $visible = null): self
	{
		$load ??= $this->load;
		$visible ??= $this->visible;

		if ($load && ! $visible) {
			throw RelationSelectionException::hiddenLoadedRelation($this->getPath());
		}

		if ($load === $this->load && $visible === $this->visible) {
			return $this;
		}

		return new self(
			$this->query,
			$this->relation,
			$this->parentRelation,
			$load,
			$visible,
			$this->fields,
			$this->conditions,
			$this->sorts,
			$this->strategy,
		);
	}

	private function withFields(array $fields): self
	{
		if (! array_is_list($fields)) {
			throw RelationSelectionException::invalidRelationFieldsType($this->getPath());
		}

		$fields = $this->normalizeSelectionFields($fields);

		if ($fields === $this->fields) {
			return $this;
		}

		return new self(
			$this->query,
			$this->relation,
			$this->parentRelation,
			$this->load,
			$this->visible,
			$fields,
			$this->conditions,
			$this->sorts,
			$this->strategy,
		);
	}

	/**
	 * @param list<ConditionInterface> $conditions
	 */
	private function withConditions(array $conditions): self
	{
		return new self(
			$this->query,
			$this->relation,
			$this->parentRelation,
			$this->load,
			$this->visible,
			$this->fields,
			[...$this->conditions, ...$conditions],
			$this->sorts,
			$this->strategy,
		);
	}

	/**
	 * @param list<Sort> $sorts
	 */
	private function withSorts(array $sorts): self
	{
		return new self(
			$this->query,
			$this->relation,
			$this->parentRelation,
			$this->load,
			$this->visible,
			$this->fields,
			$this->conditions,
			[...$this->sorts, ...$sorts],
			$this->strategy,
		);
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
