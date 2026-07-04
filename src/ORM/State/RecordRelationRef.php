<?php

declare(strict_types=1);

namespace ON\Data\ORM\State;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\ORM\Exception\StateException;

final class RecordRelationRef
{
	private ?RecordState $state = null;

	private function __construct(
		private CollectionInterface $collection,
		private string $relationName,
	) {
		if ($relationName === '') {
			throw new StateException('Record relation reference relation name cannot be empty.');
		}
	}

	public static function forCollection(CollectionInterface $collection, string $relationName): self
	{
		return new self($collection, $relationName);
	}

	public static function forState(RecordState $state, string $relationName): self
	{
		$relation = new self($state->getCollection(), $relationName);
		$relation->state = $state;

		return $relation;
	}

	public function getCollection(): CollectionInterface
	{
		return $this->collection;
	}

	public function getCollectionName(): string
	{
		return $this->collection->getName();
	}

	public function getRelationName(): string
	{
		return $this->relationName;
	}

	public function hasState(): bool
	{
		return $this->state instanceof RecordState;
	}

	public function getState(): RecordState
	{
		if (! $this->state instanceof RecordState) {
			throw new StateException(sprintf("Record relation '%s.%s' does not target a record state.", $this->getCollectionName(), $this->relationName));
		}

		return $this->state;
	}

	public function isTemplate(): bool
	{
		return ! $this->hasConcreteRecord();
	}

	public function hasConcreteRecord(): bool
	{
		return $this->state instanceof RecordState;
	}

	public function getRecordHash(): string
	{
		if ($this->state instanceof RecordState) {
			return $this->state->getStateHash();
		}

		throw new StateException(sprintf("Record relation '%s.%s' does not target a concrete record.", $this->getCollectionName(), $this->relationName));
	}
}
