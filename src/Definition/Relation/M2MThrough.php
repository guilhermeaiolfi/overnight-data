<?php

declare(strict_types=1);

namespace ON\Data\Definition\Relation;

use InvalidArgumentException;
use LogicException;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Field\FieldInterface;
use ON\Data\Support\DefinitionNode;

class M2MThrough extends DefinitionNode
{
	protected static function definitionDefaults(): array
	{
		return [
			'class' => static::class,
			'collectionName' => '',
			'inner_keys' => [],
			'outer_keys' => [],
			'where' => [],
		];
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
		$collection = $this->relation()->getParent()->getRegistry()->getCollection($this->getCollectionName());
		if ($collection === null) {
			throw new LogicException("Target collection {$this->getCollectionName()} is not registered.");
		}

		return $collection;
	}

	public function innerKey(string|array $fieldName): self
	{
		$this->set('inner_keys', $this->normalizeKeys($fieldName, 'throughInnerKey'));
		$this->validateKeyCounts();

		return $this;
	}

	public function getInnerKey(): string|array
	{
		$keys = $this->throughInnerKeys();
		if (count($keys) !== 1) {
			throw new LogicException('getInnerKey() is only available for single-key through relations. Use throughInnerKeys() instead.');
		}

		return $keys[0];
	}

	public function getInnerField(): FieldInterface
	{
		$keys = $this->throughInnerKeys();
		if (count($keys) !== 1) {
			throw new LogicException('getInnerField() is only available for single-key through relations. Use throughInnerKeys() instead.');
		}

		return $this->getCollection()->getFields()->get($keys[0]);
	}

	public function outerKey(string|array $fieldName): self
	{
		$this->set('outer_keys', $this->normalizeKeys($fieldName, 'throughOuterKey'));
		$this->validateKeyCounts();

		return $this;
	}

	public function getOuterKey(): string|array
	{
		$keys = $this->throughOuterKeys();
		if (count($keys) !== 1) {
			throw new LogicException('getOuterKey() is only available for single-key through relations. Use throughOuterKeys() instead.');
		}

		return $keys[0];
	}

	public function getOuterField(): FieldInterface
	{
		$keys = $this->throughOuterKeys();
		if (count($keys) !== 1) {
			throw new LogicException('getOuterField() is only available for single-key through relations. Use throughOuterKeys() instead.');
		}

		return $this->getCollection()->getFields()->get($keys[0]);
	}

	public function throughInnerKeys(): array
	{
		$keys = $this->get('inner_keys');
		if (! is_array($keys) || $keys === []) {
			throw new LogicException('Inner key is not defined for many-to-many through relation.');
		}

		return $keys;
	}

	public function throughOuterKeys(): array
	{
		$keys = $this->get('outer_keys');
		if (! is_array($keys) || $keys === []) {
			throw new LogicException('Outer key is not defined for many-to-many through relation.');
		}

		return $keys;
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

	public function end(): M2MRelation
	{
		return $this->relation();
	}

	private function relation(): M2MRelation
	{
		$owner = $this->owner();

		return $owner instanceof M2MRelation
			? $owner
			: throw new LogicException('Many-to-many through owner is invalid.');
	}

	private function normalizeKeys(string|array $fieldNames, string $context): array
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

	private function validateKeyCounts(): void
	{
		$innerKeys = (array) $this->get('inner_keys');
		$outerKeys = (array) $this->get('outer_keys');
		if ($innerKeys !== [] && $outerKeys !== [] && count($innerKeys) !== count($outerKeys)) {
			throw new InvalidArgumentException(
				sprintf(
					'Many-to-many through key count mismatch: throughInnerKeys has %d entries and throughOuterKeys has %d.',
					count($innerKeys),
					count($outerKeys)
				)
			);
		}

		try {
			$relationInnerKeys = $this->relation()->innerKeys();
			if ($innerKeys !== [] && count($innerKeys) !== count($relationInnerKeys)) {
				throw new InvalidArgumentException(
					sprintf(
						'Many-to-many through inner key count mismatch: relation innerKeys has %d entries and throughInnerKeys has %d.',
						count($relationInnerKeys),
						count($innerKeys)
					)
				);
			}
		} catch (LogicException) {
		}

		try {
			$relationOuterKeys = $this->relation()->outerKeys();
			if ($outerKeys !== [] && count($outerKeys) !== count($relationOuterKeys)) {
				throw new InvalidArgumentException(
					sprintf(
						'Many-to-many through outer key count mismatch: relation outerKeys has %d entries and throughOuterKeys has %d.',
						count($relationOuterKeys),
						count($outerKeys)
					)
				);
			}
		} catch (LogicException) {
		}
	}
}
