<?php

declare(strict_types=1);

namespace ON\Data\Definition\Relation;

use InvalidArgumentException;
use LogicException;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\DefinitionInterface;
use ON\Data\Definition\Display\DisplayTrait;
use ON\Data\Definition\Exception\InvalidRelationParentException;
use ON\Data\Definition\Field\FieldInterface;
use ON\Data\Definition\Interface\InterfaceTrait;
use ON\Data\Definition\MetadataTrait;
use ON\Data\Query\Relation\Loader\LoaderInterface;
use ON\Data\Support\DefinitionNode;

abstract class AbstractRelation extends DefinitionNode implements RelationInterface
{
	use DisplayTrait;
	use InterfaceTrait;
	use MetadataTrait;

	protected ?RelationKeyPairing $keyPairing = null;

	protected static function definitionDefaults(): array
	{
		return [
			'class' => static::class,
			'nullable' => false,
			'cascade' => true,
			'load' => 'lazy',
			'inner_keys' => [],
			'outer_keys' => [],
			'collectionName' => '',
			'where' => [],
			'orderBy' => [],
			'loader' => null,
			'metadata' => [],
		];
	}

	public function getParent(): DefinitionInterface
	{
		$owner = $this->owner();

		if (! $owner instanceof DefinitionInterface) {
			throw new LogicException(sprintf("Relation '%s' parent is invalid.", $this->getName()));
		}

		return $owner;
	}

	public function collection(string $collectionName): self
	{
		$this->set('collectionName', $collectionName);

		return $this;
	}

	public function getCollectionName(): string
	{
		return (string) $this->get('collectionName');
	}

	public function getCollection(): CollectionInterface
	{
		$collection = $this->getParent()->getRegistry()->getCollection($this->getCollectionName());
		if ($collection === null) {
			throw new LogicException("Target collection {$this->getCollectionName()} is not registered.");
		}

		return $collection;
	}

	public function nullable(bool $nullable): self
	{
		$this->set('nullable', $nullable);

		return $this;
	}

	public function isNullable(): bool
	{
		return (bool) $this->get('nullable');
	}

	public function where(array $where): self
	{
		$this->set('where', $where);

		return $this;
	}

	public function getWhere(): array
	{
		$where = $this->get('where');

		return is_array($where) ? $where : [];
	}

	public function orderBy(array $orderBy): self
	{
		$this->set('orderBy', $orderBy);

		return $this;
	}

	public function getOrderBy(): array
	{
		$orderBy = $this->get('orderBy');

		return is_array($orderBy) ? $orderBy : [];
	}

	public function cascade(bool $cascade): self
	{
		$this->set('cascade', $cascade);

		return $this;
	}

	public function isCascade(): bool
	{
		return (bool) $this->get('cascade');
	}

	public function load(string $load): self
	{
		$this->set('load', $load);

		return $this;
	}

	public function getLoadStrategy(): string
	{
		return (string) $this->get('load');
	}

	public function innerKey(string|array $fieldName): self
	{
		$this->set('inner_keys', $this->normalizeKeys($fieldName, 'innerKey'));
		$this->validateRelationKeys();
		$this->resetKeyPairing();

		return $this;
	}

	public function getInnerKey(): string|array
	{
		$keys = $this->getInnerKeys();
		if (count($keys) !== 1) {
			throw new LogicException('getInnerKey() is only available for single-key relations. Use getInnerKeys() instead.');
		}

		return $keys[0];
	}

	public function getInnerKeys(): array
	{
		$keys = $this->get('inner_keys');
		if (! is_array($keys) || $keys === []) {
			throw new LogicException("Inner key is not defined for relation {$this->getName()}.");
		}

		return $keys;
	}

	public function getInnerField(): FieldInterface
	{
		$keys = $this->getInnerKeys();
		if (count($keys) !== 1) {
			throw new LogicException('getInnerField() is only available for single-key relations. Use getInnerKeys() instead.');
		}

		return $this->getParent()->getFields()->get($keys[0]);
	}

	public function outerKey(string|array $fieldName): self
	{
		$this->set('outer_keys', $this->normalizeKeys($fieldName, 'outerKey'));
		$this->validateRelationKeys();
		$this->resetKeyPairing();

		return $this;
	}

	public function getOuterKey(): string|array
	{
		$keys = $this->getOuterKeys();
		if (count($keys) !== 1) {
			throw new LogicException('getOuterKey() is only available for single-key relations. Use getOuterKeys() instead.');
		}

		return $keys[0];
	}

	public function getOuterKeys(): array
	{
		$keys = $this->get('outer_keys');
		if (! is_array($keys) || $keys === []) {
			throw new LogicException("Outer key is not defined for relation {$this->getName()}.");
		}

		return $keys;
	}

	public function getOuterField(): FieldInterface
	{
		$keys = $this->getOuterKeys();
		if (count($keys) !== 1) {
			throw new LogicException('getOuterField() is only available for single-key relations. Use getOuterKeys() instead.');
		}

		return $this->getCollection()->getFields()->get($keys[0]);
	}

	public function getKeyPairing(): RelationKeyPairing
	{
		return $this->keyPairing ??= RelationKeyPairing::from(
			$this->getInnerKeys(),
			$this->getOuterKeys(),
		);
	}

	public function loader(string $loader): self
	{
		if (! is_a($loader, LoaderInterface::class, true)) {
			throw new InvalidArgumentException(sprintf(
				'Relation "%s" loader must implement %s.',
				$this->getName(),
				LoaderInterface::class,
			));
		}

		$this->set('loader', $loader);

		return $this;
	}

	public function getLoader(): string
	{
		$value = $this->get('loader');

		if (! is_string($value) || $value === '') {
			throw new LogicException(sprintf('Relation "%s" does not define a loader.', $this->getName()));
		}

		if (! is_a($value, LoaderInterface::class, true)) {
			throw new InvalidArgumentException(sprintf(
				'Relation "%s" loader must implement %s.',
				$this->getName(),
				LoaderInterface::class,
			));
		}

		return $value;
	}

	public function getCardinality(): string
	{
		return 'single';
	}

	public function isJunction(): bool
	{
		return false;
	}

	public function end(): DefinitionInterface
	{
		return $this->getParent();
	}

	protected function requireCollectionParent(string $context): CollectionInterface
	{
		$parent = $this->getParent();
		if (! $parent instanceof CollectionInterface) {
			throw new InvalidRelationParentException(
				sprintf("%s requires a collection parent, '%s' given.", $context, $parent::class)
			);
		}

		return $parent;
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
		$innerKeys = (array) $this->get('inner_keys');
		$outerKeys = (array) $this->get('outer_keys');

		if ($innerKeys !== [] && $outerKeys !== [] && count($innerKeys) !== count($outerKeys)) {
			throw new InvalidArgumentException(
				sprintf(
					"Relation '%s' key count mismatch: innerKeys has %d entries and outerKeys has %d.",
					$this->getName() ?: '(unnamed)',
					count($innerKeys),
					count($outerKeys)
				)
			);
		}

		if ($innerKeys !== [] && count($innerKeys) !== count(array_unique($innerKeys))) {
			throw new InvalidArgumentException("Relation '{$this->getName()}' contains duplicate inner keys.");
		}

		if ($outerKeys !== [] && count($outerKeys) !== count(array_unique($outerKeys))) {
			throw new InvalidArgumentException("Relation '{$this->getName()}' contains duplicate outer keys.");
		}

		$target = $this->getParent()->getRegistry()->getCollection($this->getCollectionName());
		if ($target !== null && $outerKeys !== []) {
			$targetPrimaryKeyCount = count($target->getPrimaryKey());
			if ($targetPrimaryKeyCount !== count($outerKeys)) {
				throw new InvalidArgumentException(
					sprintf(
						"Relation '%s' outerKeys count %d does not match target collection '%s' primary key count %d.",
						$this->getName() ?: '(unnamed)',
						count($outerKeys),
						$target->getName(),
						$targetPrimaryKeyCount
					)
				);
			}
		}
	}

	protected function initializeRuntimeState(): void
	{
		$this->display = null;
		$this->interface = null;
		$this->metadataMap = null;
		$this->keyPairing = null;
	}

	public function resetKeyPairing(): void
	{
		$this->keyPairing = null;
	}
}
