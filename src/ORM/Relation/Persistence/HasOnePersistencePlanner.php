<?php

declare(strict_types=1);

namespace ON\Data\ORM\Relation\Persistence;

use ON\Data\Definition\Relation\BelongsToRelation;
use ON\Data\Definition\Relation\HasOneRelation;
use ON\Data\Definition\Relation\RelationInterface;
use ON\Data\ORM\Exception\RelationPersistenceException;
use ON\Data\ORM\Persistence\PersistenceContext;
use ON\Data\ORM\Record\RecordState;
use ON\Data\ORM\Relation\RelationStateInterface;
use ON\Data\ORM\Relation\ToOneRelationState;

final class HasOnePersistencePlanner implements RelationPersistencePlannerInterface
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
		if (! $change instanceof ToOneRelationState) {
			throw new RelationPersistenceException(sprintf(
				"Relation '%s' must be a related reference to use %s.",
				$change->getRelationName(),
				self::class,
			));
		}

		$reference = $change;
		if (! $relation instanceof HasOneRelation || $relation instanceof BelongsToRelation) {
			throw new RelationPersistenceException(sprintf(
				"Relation '%s' must be a has-one relation to use %s.",
				$reference->getRelationName(),
				self::class,
			));
		}

		$owner = $reference->getOwner();
		$currentTarget = $reference->getTarget();
		$baselineTarget = $reference->getBaselineTarget();
		$requiresUnlink = $baselineTarget !== null && $baselineTarget !== $currentTarget;

		if ($requiresUnlink && ! $relation->isExclusive() && ! $relation->isNullable()) {
			throw new RelationPersistenceException(sprintf(
				"Relation '%s' on owner collection '%s' cannot unlink target by nulling outer keys because the relation is not nullable.",
				$relation->getName(),
				$owner->getCollection()->getName(),
			));
		}

		$currentRecord = $currentTarget === null
			? null
			: $this->records->resolve($context, $relation, $currentTarget, 'target');
		$baselineRecord = $requiresUnlink && $baselineTarget !== null
			? $this->records->resolve($context, $relation, $baselineTarget, 'target')
			: null;
		if ($baselineRecord instanceof RecordState) {
			if ($relation->isExclusive()) {
				if (! $baselineRecord->isRemoved()) {
					$baselineRecord->markRemoved();
				}
			} else {
				$this->keys->nullValues($baselineRecord, $relation->getOuterKeys());
			}
		}

		if ($currentRecord instanceof RecordState) {
			$this->copyOwnerKeysIntoTarget($relation, $owner, $currentRecord);
		}
	}

	private function copyOwnerKeysIntoTarget(HasOneRelation $relation, RecordState $owner, RecordState $target): void
	{
		$ownerCollection = $owner->getCollection()->getName();
		$this->keys->copyValues(
			$relation->getName(),
			$relation->getInnerKeys(),
			$relation->getOuterKeys(),
			$owner,
			$target,
			static fn (string $relationName, string $sourceField, int|string $index): RelationPersistenceException => new RelationPersistenceException(sprintf(
				"Relation '%s' is missing an outer key field for owner collection '%s' field '%s'.",
				$relationName,
				$ownerCollection,
				$sourceField,
			)),
		);
	}
}
