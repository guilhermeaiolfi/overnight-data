<?php

declare(strict_types=1);

namespace ON\Data\Definition\Relation;

use InvalidArgumentException;
use LogicException;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Field\FieldInterface;

class M2MThrough
{
	protected string $collectionName;
	protected array $inner_keys = [];
	protected array $outer_keys = [];
	protected array $where = [];

	public function __construct(
		protected M2MRelation $m2m
	) {

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
		$collection = $this->m2m->getParent()->getRegistry()->getCollection($this->collectionName);
		if ($collection === null) {
			throw new LogicException("Target collection {$this->collectionName} is not registered.");
		}

		return $collection;
	}

	public function innerKey(string|array $fieldName): self
	{
		$this->inner_keys = $this->normalizeKeys($fieldName, 'throughInnerKey');
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

		return $this->getCollection()->fields->get($keys[0]);
	}

	public function outerKey(string|array $fieldName): self
	{
		$this->outer_keys = $this->normalizeKeys($fieldName, 'throughOuterKey');
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

		return $this->getCollection()->fields->get($keys[0]);
	}

	public function throughInnerKeys(): array
	{
		if ($this->inner_keys === []) {
			throw new LogicException('Inner key is not defined for many-to-many through relation.');
		}

		return $this->inner_keys;
	}

	public function throughOuterKeys(): array
	{
		if ($this->outer_keys === []) {
			throw new LogicException('Outer key is not defined for many-to-many through relation.');
		}

		return $this->outer_keys;
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

	public function end(): M2MRelation
	{
		return $this->m2m;
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
		if ($this->inner_keys !== [] && $this->outer_keys !== [] && count($this->inner_keys) !== count($this->outer_keys)) {
			throw new InvalidArgumentException(
				sprintf(
					'Many-to-many through key count mismatch: throughInnerKeys has %d entries and throughOuterKeys has %d.',
					count($this->inner_keys),
					count($this->outer_keys)
				)
			);
		}

		try {
			$relationInnerKeys = $this->m2m->innerKeys();
			if ($this->inner_keys !== [] && count($this->inner_keys) !== count($relationInnerKeys)) {
				throw new InvalidArgumentException(
					sprintf(
						'Many-to-many through inner key count mismatch: relation innerKeys has %d entries and throughInnerKeys has %d.',
						count($relationInnerKeys),
						count($this->inner_keys)
					)
				);
			}
		} catch (LogicException) {
		}

		try {
			$relationOuterKeys = $this->m2m->outerKeys();
			if ($this->outer_keys !== [] && count($this->outer_keys) !== count($relationOuterKeys)) {
				throw new InvalidArgumentException(
					sprintf(
						'Many-to-many through outer key count mismatch: relation outerKeys has %d entries and throughOuterKeys has %d.',
						count($relationOuterKeys),
						count($this->outer_keys)
					)
				);
			}
		} catch (LogicException) {
		}
	}
}
