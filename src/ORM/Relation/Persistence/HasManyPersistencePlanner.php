<?php

declare(strict_types=1);

namespace ON\Data\ORM\Relation\Persistence;

use ON\Data\Definition\Relation\HasManyRelation;
use ON\Data\Definition\Relation\RelationInterface;
use ON\Data\ORM\Exception\RelationPersistenceException;
use ON\Data\ORM\Persistence\PersistenceContext;
use ON\Data\ORM\Record\RecordState;
use ON\Data\ORM\Relation\RelationStateInterface;
use ON\Data\ORM\Relation\ToManyRelationState;

final class HasManyPersistencePlanner implements RelationPersistencePlannerInterface
{
	private TrackedRecordResolver $records;
	private ForeignKeyWriter $keys;

	public function __construct(?TrackedRecordResolver $records = null, ?ForeignKeyWriter $keys = null)
	{
		$this->records = $records ?? new TrackedRecordResolver();
		$this->keys = $keys ?? new ForeignKeyWriter();
	}

	public function plan(PersistenceContext $context, RelationInterface $relation, RelationStateInterface $change): void
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
			$child = $this->records->resolve($context, $relation, $item, 'child');
			$this->copyOwnerKeysIntoChild($relation, $owner, $child);
		}

		foreach ($collection->getRemoved() as $item) {
			$child = $this->records->resolve($context, $relation, $item, 'child');
			if ($relation->isExclusive()) {
				if (! $child->isRemoved()) {
					$child->markRemoved();
				}

				continue;
			}

			if (! $relation->isNullable()) {
				throw new RelationPersistenceException(sprintf(
					"Relation '%s' on owner collection '%s' cannot remove child by nulling outer keys because the relation is not nullable.",
					$relation->getName(),
					$owner->getCollection()->getName(),
				));
			}

			$this->keys->nullValues($child, $relation->getOuterKeys());
		}
	}

	private function copyOwnerKeysIntoChild(HasManyRelation $relation, RecordState $owner, RecordState $child): void
	{
		$ownerCollection = $owner->getCollection()->getName();
		$this->keys->copyValues(
			$relation->getName(),
			$relation->getInnerKeys(),
			$relation->getOuterKeys(),
			$owner,
			$child,
			static fn (string $relationName, string $sourceField, int|string $index): RelationPersistenceException => new RelationPersistenceException(sprintf(
				"Relation '%s' is missing an outer key field for owner collection '%s' field '%s'.",
				$relationName,
				$ownerCollection,
				$sourceField,
			)),
		);
	}
}
