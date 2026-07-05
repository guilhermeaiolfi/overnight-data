<?php

declare(strict_types=1);

namespace ON\Data\ORM\Relation\Persistence;

use ON\Data\Definition\Relation\HasManyRelation;
use ON\Data\Definition\Relation\RelationInterface;
use ON\Data\ORM\Exception\RelationPersistenceException;
use ON\Data\ORM\Persistence\PersistenceContext;
use ON\Data\ORM\Relation\RelationChangeInterface;
use ON\Data\ORM\Relation\ToManyRelationState;
use ON\Data\ORM\State\RecordState;

final class HasManyPersistencePlanner implements RelationPersistencePlannerInterface
{
	public function plan(PersistenceContext $context, RelationInterface $relation, RelationChangeInterface $change): void
	{
		if (! $change instanceof ToManyRelationState) {
			throw new RelationPersistenceException(sprintf(
				"Relation '%s' must be a related collection to use %s.",
				$change->getRelationName(),
				self::class,
			));
		}

		$collection = $change;
		if (! $relation instanceof HasManyRelation) {
			throw new RelationPersistenceException(sprintf(
				"Relation '%s' must be a has-many relation to use %s.",
				$collection->getRelationName(),
				self::class,
			));
		}

		$owner = $collection->getOwner();

		foreach ($collection->getAdded() as $item) {
			$child = $this->resolveChildRecord($context, $relation, $item);
			$this->copyOwnerKeysIntoChild($relation, $owner, $child);
		}

		foreach ($collection->getRemoved() as $item) {
			$child = $this->resolveChildRecord($context, $relation, $item);
			if (! $relation->isNullable()) {
				throw new RelationPersistenceException(sprintf(
					"Relation '%s' on owner collection '%s' cannot remove child by nulling outer keys because the relation is not nullable.",
					$relation->getName(),
					$owner->getCollectionName(),
				));
			}

			$this->nullChildOuterKeys($relation, $child);
		}
	}

	private function resolveChildRecord(PersistenceContext $context, RelationInterface $relation, object $item): RecordState
	{
		$tracked = $context->getRepresentations()->get($item);
		if ($tracked === null) {
			throw new RelationPersistenceException(sprintf(
				"Relation '%s' child item is not tracked.",
				$relation->getName(),
			));
		}

		$child = $context->getRecords()->getFromRepresentation($tracked);
		if (! $child instanceof RecordState) {
			throw new RelationPersistenceException(sprintf(
				"Relation '%s' tracked child item cannot be resolved to a record state.",
				$relation->getName(),
			));
		}

		return $child;
	}

	private function copyOwnerKeysIntoChild(HasManyRelation $relation, RecordState $owner, RecordState $child): void
	{
		foreach ($relation->getInnerKeys() as $index => $innerField) {
			$innerField = (string) $innerField;
			$outerField = $relation->getOuterKeys()[$index] ?? null;
			if (! is_string($outerField) || $outerField === '') {
				throw new RelationPersistenceException(sprintf(
					"Relation '%s' is missing an outer key field for owner collection '%s' field '%s'.",
					$relation->getName(),
					$owner->getCollectionName(),
					$innerField,
				));
			}

			$child->setValue($outerField, $owner->getValueRef($innerField));
		}
	}

	private function nullChildOuterKeys(HasManyRelation $relation, RecordState $child): void
	{
		foreach ($relation->getOuterKeys() as $outerField) {
			$child->setValue((string) $outerField, null);
		}
	}
}
