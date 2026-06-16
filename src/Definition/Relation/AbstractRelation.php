<?php

declare(strict_types=1);

namespace ON\Data\Definition\Relation;

use InvalidArgumentException;
use LogicException;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Display\DisplayTrait;
use ON\Data\Definition\Field\FieldInterface;
use ON\Data\Definition\Interface\InterfaceTrait;
use ON\Data\Definition\MetadataTrait;

abstract class AbstractRelation implements RelationInterface
{
	use DisplayTrait;
	use InterfaceTrait;
	use MetadataTrait;
	// Defines if relation can be nullable (child can have no parent). Defaults to false
	protected bool $nullable = false;

	// Automatically save related data with parent entity. Defaults to true
	protected bool $cascade = true;

	// lazy || eager
	protected string $load = "lazy";

	protected array $inner_keys = [];

	protected array $outer_keys = [];

	protected string $collectionName;

	protected string $name;

	protected array $where = [];

	// format: ['key1' => 'asc', 'key2' => 'asc']
	protected array $orderBy = [];

	protected ?string $loader = null;

	public function __construct(
		public CollectionInterface $parent
	) {

	}

	public function getParent(): CollectionInterface
	{
		return $this->parent;
	}

	public function name(string $name): self
	{
		$this->name = $name;

		return $this;
	}

	public function getName(): string
	{
		return $this->name;
	}

	public function collection(string $collectionName): self
	{
		$this->collectionName = $collectionName;

		return $this;
	}

	public function getCollectionName(): string
	{
		return $this->collectionName;
	}

	public function getCollection(): CollectionInterface
	{
		$collection = $this->parent->getRegistry()->getCollection($this->collectionName);
		if ($collection === null) {
			throw new LogicException("Target collection {$this->collectionName} is not registered.");
		}

		return $collection;
	}

	public function nullable(bool $nullable): self
	{
		$this->nullable = $nullable;

		return $this;
	}

	public function isNullable(): bool
	{
		return $this->nullable;
	}

	public function where(array $where): self
	{
		$this->where = $where;

		return $this;
	}

	public function getWhere(): array
	{
		return $this->where;
	}

	public function orderBy(array $orderBy): self
	{
		$this->orderBy = $orderBy;

		return $this;
	}

	public function getOrderBy(): array
	{
		return $this->orderBy;
	}

	public function cascade(bool $cascade): self
	{
		$this->cascade = $cascade;

		return $this;
	}

	public function isCascade(): bool
	{
		return $this->cascade;
	}

	public function load(string $load): self
	{
		$this->load = $load;

		return $this;
	}

	public function getLoadStrategy(): string
	{
		return $this->load;
	}

	public function innerKey(string|array $fieldName): self
	{
		$this->inner_keys = $this->normalizeKeys($fieldName, 'innerKey');
		$this->validateRelationKeys();

		return $this;
	}

	public function getInnerKey(): string|array
	{
		$keys = $this->innerKeys();
		if (count($keys) !== 1) {
			throw new LogicException('getInnerKey() is only available for single-key relations. Use innerKeys() instead.');
		}

		return $keys[0];
	}

	public function innerKeys(): array
	{
		if ($this->inner_keys === []) {
			throw new LogicException("Inner key is not defined for relation {$this->name}.");
		}

		return $this->inner_keys;
	}

	public function getInnerField(): FieldInterface
	{
		$keys = $this->innerKeys();
		if (count($keys) !== 1) {
			throw new LogicException('getInnerField() is only available for single-key relations. Use innerKeys() instead.');
		}

		return $this->parent->fields->get($keys[0]);
	}

	public function outerKey(string|array $fieldName): self
	{
		$this->outer_keys = $this->normalizeKeys($fieldName, 'outerKey');
		$this->validateRelationKeys();

		return $this;
	}

	public function getOuterKey(): string|array
	{
		$keys = $this->outerKeys();
		if (count($keys) !== 1) {
			throw new LogicException('getOuterKey() is only available for single-key relations. Use outerKeys() instead.');
		}

		return $keys[0];
	}

	public function outerKeys(): array
	{
		if ($this->outer_keys === []) {
			throw new LogicException("Outer key is not defined for relation {$this->name}.");
		}

		return $this->outer_keys;
	}

	public function getOuterField(): FieldInterface
	{
		$keys = $this->outerKeys();
		if (count($keys) !== 1) {
			throw new LogicException('getOuterField() is only available for single-key relations. Use outerKeys() instead.');
		}

		return $this->getCollection()->fields->get($keys[0]);
	}

	public function loader(string $loader): self
	{
		$this->loader = $loader;

		return $this;
	}

	public function getLoader(): ?string
	{
		return $this->loader;
	}

	public function getCardinality(): string
	{
		return 'single';
	}

	public function isJunction(): bool
	{
		return false;
	}

	public function end(): CollectionInterface
	{
		return $this->parent;
	}

	protected function normalizeKeys(string|array $fieldNames, string $context): array
	{
		$keys = is_array($fieldNames) ? array_values($fieldNames) : [$fieldNames];
		if ($keys === []) {
			throw new InvalidArgumentException("{$context} cannot be empty.");
		}

		$normalized = [];
		foreach ($keys as $fieldName) {
			$fieldName = (string) $fieldName;
			if ($fieldName === '') {
				throw new InvalidArgumentException("{$context} cannot contain empty key names.");
			}
			if (in_array($fieldName, $normalized, true)) {
				throw new InvalidArgumentException("{$context} cannot contain duplicate key '{$fieldName}'.");
			}
			$normalized[] = $fieldName;
		}

		return $normalized;
	}

	protected function validateRelationKeys(): void
	{
		if ($this->inner_keys !== [] && $this->outer_keys !== [] && count($this->inner_keys) !== count($this->outer_keys)) {
			throw new InvalidArgumentException(
				sprintf(
					"Relation '%s' key count mismatch: innerKeys has %d entries and outerKeys has %d.",
					$this->name ?? '(unnamed)',
					count($this->inner_keys),
					count($this->outer_keys)
				)
			);
		}

		if ($this->inner_keys !== [] && count($this->inner_keys) !== count(array_unique($this->inner_keys))) {
			throw new InvalidArgumentException("Relation '{$this->name}' contains duplicate inner keys.");
		}

		if ($this->outer_keys !== [] && count($this->outer_keys) !== count(array_unique($this->outer_keys))) {
			throw new InvalidArgumentException("Relation '{$this->name}' contains duplicate outer keys.");
		}

		$target = $this->parent->getRegistry()->getCollection($this->collectionName ?? '');
		if ($target !== null && $this->outer_keys !== []) {
			$targetPrimaryKeyCount = count($target->getPrimaryKey()->getFieldNames());
			if ($targetPrimaryKeyCount !== count($this->outer_keys)) {
				throw new InvalidArgumentException(
					sprintf(
						"Relation '%s' outerKeys count %d does not match target collection '%s' primary key count %d.",
						$this->name ?? '(unnamed)',
						count($this->outer_keys),
						$target->getName(),
						$targetPrimaryKeyCount
					)
				);
			}
		}
	}
}
