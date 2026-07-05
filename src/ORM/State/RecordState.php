<?php

declare(strict_types=1);

namespace ON\Data\ORM\State;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Key;
use ON\Data\ORM\Exception\StateException;

final class RecordState
{
	private CollectionInterface $collection;
	private ?Key $key;
	private string $stateHash;
	private RecordLifecycle $lifecycle;
	private int $revision = 1;
	private RecordHistory $history;
	/** @var array<string, mixed> */
	private array $originalValues;
	/** @var array<string, mixed> */
	private array $values;

	/**
	 * @param array<string, mixed> $values
	 */
	private function __construct(CollectionInterface $collection, ?Key $key, array $values, RecordLifecycle $lifecycle)
	{
		$this->collection = $collection;
		$this->key = $key;
		$this->stateHash = $key instanceof Key
			? $key->getHash()
			: sprintf('%s#%d', $collection->getName(), spl_object_id($this));
		$this->values = $values;
		$this->originalValues = $values;
		$this->lifecycle = $lifecycle;
		$this->history = new RecordHistory();
		$this->history->record($this->revision, $values);
	}

	/**
	 * @param array<string, mixed> $values
	 */
	public static function new(CollectionInterface $collection, array $values = []): self
	{
		return new self($collection, null, $values, RecordLifecycle::NEW);
	}

	/**
	 * @param array<string, mixed> $values
	 */
	public static function clean(Key $key, array $values): self
	{
		return new self($key->getCollection(), $key, $values, RecordLifecycle::CLEAN);
	}

	public function getCollection(): CollectionInterface
	{
		return $this->collection;
	}

	public function getKey(): ?Key
	{
		return $this->key;
	}

	public function hasKey(): bool
	{
		return $this->key instanceof Key;
	}

	public function getStateHash(): string
	{
		return $this->stateHash;
	}

	public function getLifecycle(): RecordLifecycle
	{
		return $this->lifecycle;
	}

	public function isNew(): bool
	{
		return $this->lifecycle === RecordLifecycle::NEW;
	}

	public function isClean(): bool
	{
		return $this->lifecycle === RecordLifecycle::CLEAN;
	}

	public function isDirty(): bool
	{
		return $this->lifecycle === RecordLifecycle::DIRTY;
	}

	public function isRemoved(): bool
	{
		return $this->lifecycle === RecordLifecycle::REMOVED;
	}

	public function getRevision(): int
	{
		return $this->revision;
	}

	public function getHistory(): RecordHistory
	{
		return $this->history;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getOriginalValues(): array
	{
		return $this->originalValues;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getValues(): array
	{
		return $this->values;
	}

	public function hasValue(string $field): bool
	{
		return array_key_exists($field, $this->values);
	}

	public function getValue(string $field): mixed
	{
		if (! array_key_exists($field, $this->values)) {
			throw new StateException(sprintf("Record state for collection '%s' does not contain field '%s'.", $this->collection->getName(), $field));
		}

		return $this->values[$field];
	}

	public function getValueRef(string $field): ValueRef
	{
		return ValueRef::field($this, $field);
	}

	public function setValue(string $field, mixed $value): void
	{
		$value = $this->normalizeValue($value);

		if (array_key_exists($field, $this->values) && $this->valuesAreSame($this->values[$field], $value)) {
			return;
		}

		$values = $this->values;
		$values[$field] = $value;
		$this->applyChangedValues($values);
	}

	/**
	 * @param array<string, mixed> $values
	 */
	public function setValues(array $values): void
	{
		$nextValues = $this->values;
		$changed = false;
		foreach ($values as $field => $value) {
			$value = $this->normalizeValue($value);

			if (! array_key_exists($field, $nextValues) || ! $this->valuesAreSame($nextValues[$field], $value)) {
				$nextValues[$field] = $value;
				$changed = true;
			}
		}

		if ($changed) {
			$this->applyChangedValues($nextValues);
		}
	}

	/**
	 * @return list<string>
	 */
	public function getDirtyFields(): array
	{
		if ($this->isNew()) {
			return array_keys($this->values);
		}

		$fields = [];
		foreach ($this->values as $field => $value) {
			if (! array_key_exists($field, $this->originalValues) || ! $this->valuesAreSame($this->originalValues[$field], $value)) {
				$fields[] = $field;
			}
		}

		foreach (array_keys($this->originalValues) as $field) {
			if (! array_key_exists($field, $this->values)) {
				$fields[] = $field;
			}
		}

		return $fields;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getDirtyValues(): array
	{
		$dirty = [];
		foreach ($this->getDirtyFields() as $field) {
			if (array_key_exists($field, $this->values)) {
				$dirty[$field] = $this->values[$field];
			}
		}

		return $dirty;
	}

	public function resolveValueRefs(): bool
	{
		$values = $this->values;
		$changed = false;

		foreach ($values as $field => $value) {
			if (! $value instanceof ValueRef || ! $value->isResolved()) {
				continue;
			}

			$resolved = $value->resolve();
			if (! $this->valuesAreSame($value, $resolved)) {
				$values[$field] = $resolved;
				$changed = true;
			}
		}

		if (! $changed) {
			return false;
		}

		$this->applyChangedValues($values);

		return true;
	}

	public function hasValueRefs(): bool
	{
		foreach ($this->values as $value) {
			if ($value instanceof ValueRef) {
				return true;
			}
		}

		return false;
	}

	public function hasUnresolvedValueRefs(): bool
	{
		return $this->getUnresolvedValueRefs() !== [];
	}

	/**
	 * @return array<string, ValueRef>
	 */
	public function getUnresolvedValueRefs(): array
	{
		$unresolved = [];
		foreach ($this->values as $field => $value) {
			if ($value instanceof ValueRef && ! $value->isResolved()) {
				$unresolved[$field] = $value;
			}
		}

		return $unresolved;
	}

	public function markClean(?Key $key = null): void
	{
		if ($key instanceof Key) {
			$this->key = $this->collection->getKey($key);
		}

		$this->originalValues = $this->values;
		$this->lifecycle = RecordLifecycle::CLEAN;
	}

	public function markRemoved(): void
	{
		$this->lifecycle = RecordLifecycle::REMOVED;
	}

	/**
	 * @param array<string, mixed> $values
	 */
	private function applyChangedValues(array $values): void
	{
		$this->values = $values;
		++$this->revision;
		$this->history->record($this->revision, $this->values);
		if (! $this->isNew() && ! $this->isRemoved()) {
			$this->lifecycle = RecordLifecycle::DIRTY;
		}
	}

	private function normalizeValue(mixed $value): mixed
	{
		if ($value instanceof ValueRef && $value->isResolved()) {
			return $value->resolve();
		}

		return $value;
	}

	private function valuesAreSame(mixed $left, mixed $right): bool
	{
		if ($left instanceof ValueRef && $right instanceof ValueRef) {
			return $left->equals($right);
		}

		return $left === $right;
	}
}
