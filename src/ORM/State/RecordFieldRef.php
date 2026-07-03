<?php

declare(strict_types=1);

namespace ON\Data\ORM\State;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Key;
use ON\Data\ORM\Exception\StateException;

final class RecordFieldRef
{
	private ?RecordState $state = null;

	public function __construct(
		private CollectionInterface $collection,
		private string $fieldName,
		private ?Key $key = null,
	) {
		if ($fieldName === '') {
			throw new StateException('Record field reference field name cannot be empty.');
		}
	}

	public static function template(CollectionInterface $collection, string $fieldName): self
	{
		return new self($collection, $fieldName);
	}

	public static function forKey(Key $key, string $fieldName): self
	{
		return new self($key->getCollection(), $fieldName, $key);
	}

	public static function forState(RecordState $state, string $fieldName): self
	{
		$field = new self($state->getCollection(), $fieldName);
		$field->state = $state;

		return $field;
	}

	public function getCollection(): CollectionInterface
	{
		return $this->collection;
	}

	public function getCollectionName(): string
	{
		return $this->collection->getName();
	}

	public function getFieldName(): string
	{
		return $this->fieldName;
	}

	public function getKey(): ?Key
	{
		if ($this->state instanceof RecordState && $this->state->hasKey()) {
			return $this->state->getKey();
		}

		return $this->key;
	}

	public function hasKey(): bool
	{
		return $this->getKey() instanceof Key;
	}

	public function hasState(): bool
	{
		return $this->state instanceof RecordState;
	}

	public function getState(): RecordState
	{
		if (! $this->state instanceof RecordState) {
			throw new StateException(sprintf("Record field '%s.%s' does not target a record state.", $this->getCollectionName(), $this->fieldName));
		}

		return $this->state;
	}

	public function isTemplate(): bool
	{
		return ! $this->hasConcreteRecord();
	}

	public function hasConcreteRecord(): bool
	{
		return $this->key instanceof Key || $this->state instanceof RecordState;
	}

	public function getRecordHash(): string
	{
		if ($this->state instanceof RecordState) {
			return $this->state->getStateHash();
		}

		if ($this->key instanceof Key) {
			return $this->key->getHash();
		}

		throw new StateException(sprintf("Record field '%s.%s' does not target a concrete record.", $this->getCollectionName(), $this->fieldName));
	}
}
