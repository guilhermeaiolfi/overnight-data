<?php

declare(strict_types=1);

namespace ON\Data\ORM\Relation\Persistence;

use ON\Data\Definition\Relation\BelongsToRelation;
use ON\Data\Definition\Relation\RelationInterface;
use ON\Data\ORM\Exception\RelationPersistenceException;
use ON\Data\ORM\Persistence\PersistenceContext;
use ON\Data\ORM\Record\RecordState;
use ON\Data\ORM\Relation\RelationStateInterface;
use ON\Data\ORM\Relation\ToOneRelationState;

final class BelongsToPersistencePlanner implements RelationPersistencePlannerInterface
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
		if (! $relation instanceof BelongsToRelation) {
			throw new RelationPersistenceException(sprintf(
				"Relation '%s' must be a belongs-to relation to use %s.",
				$reference->getRelationName(),
				self::class,
			));
		}

		$owner = $reference->getOwner();
		$target = $reference->getTarget();
		if ($target !== null) {
			$targetRecord = $this->records->resolve($context, $relation, $target, 'target');
			$this->copyTargetKeysIntoOwner($relation, $targetRecord, $owner);

			return;
		}

		if (! $relation->isNullable()) {
			throw new RelationPersistenceException(sprintf(
				"Relation '%s' on owner collection '%s' cannot clear target by nulling inner keys because the relation is not nullable.",
				$relation->getName(),
				$owner->getCollection()->getName(),
			));
		}

		$this->keys->nullValues($owner, $relation->getInnerKeys());
	}

	private function copyTargetKeysIntoOwner(BelongsToRelation $relation, RecordState $target, RecordState $owner): void
	{
		$targetCollection = $target->getCollection()->getName();
		$this->keys->copyValues(
			$relation->getName(),
			$relation->getOuterKeys(),
			$relation->getInnerKeys(),
			$target,
			$owner,
			static fn (string $relationName, string $sourceField, int|string $index): RelationPersistenceException => new RelationPersistenceException(sprintf(
				"Relation '%s' is missing an inner key field for target collection '%s' field '%s'.",
				$relationName,
				$targetCollection,
				$sourceField,
			)),
		);
	}
}
