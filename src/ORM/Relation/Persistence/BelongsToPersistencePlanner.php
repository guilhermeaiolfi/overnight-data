<?php

declare(strict_types=1);

namespace ON\Data\ORM\Relation\Persistence;

use ON\Data\Definition\Relation\BelongsToRelation;
use ON\Data\Definition\Relation\RelationInterface;
use ON\Data\ORM\Exception\RelationPersistenceException;
use ON\Data\ORM\Persistence\PersistenceContext;
use ON\Data\ORM\Relation\RelatedReference;
use ON\Data\ORM\Relation\RelationChangeInterface;
use ON\Data\ORM\State\RecordState;

final class BelongsToPersistencePlanner implements RelationPersistencePlannerInterface
{
	public function plan(PersistenceContext $context, RelationInterface $relation, RelationChangeInterface $change): void
	{
		if (! $change instanceof RelatedReference) {
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
			$targetRecord = $this->resolveTargetRecord($context, $relation, $target);
			$this->copyTargetKeysIntoOwner($relation, $targetRecord, $owner);

			return;
		}

		if (! $relation->isNullable()) {
			throw new RelationPersistenceException(sprintf(
				"Relation '%s' on owner collection '%s' cannot clear target by nulling inner keys because the relation is not nullable.",
				$relation->getName(),
				$owner->getCollectionName(),
			));
		}

		$this->nullOwnerInnerKeys($relation, $owner);
	}

	private function resolveTargetRecord(PersistenceContext $context, BelongsToRelation $relation, object $target): RecordState
	{
		$tracked = $context->getRepresentations()->get($target);
		if ($tracked === null) {
			throw new RelationPersistenceException(sprintf(
				"Relation '%s' target item is not tracked.",
				$relation->getName(),
			));
		}

		$targetRecord = $context->getRecords()->getFromRepresentation($tracked);
		if (! $targetRecord instanceof RecordState) {
			throw new RelationPersistenceException(sprintf(
				"Relation '%s' tracked target item cannot be resolved to a record state.",
				$relation->getName(),
			));
		}

		return $targetRecord;
	}

	private function copyTargetKeysIntoOwner(BelongsToRelation $relation, RecordState $target, RecordState $owner): void
	{
		foreach ($relation->getOuterKeys() as $index => $outerField) {
			$outerField = (string) $outerField;
			$innerField = $relation->getInnerKeys()[$index] ?? null;
			if (! is_string($innerField) || $innerField === '') {
				throw new RelationPersistenceException(sprintf(
					"Relation '%s' is missing an inner key field for target collection '%s' field '%s'.",
					$relation->getName(),
					$target->getCollectionName(),
					$outerField,
				));
			}

			$owner->setValue($innerField, $target->getValueRef($outerField));
		}
	}

	private function nullOwnerInnerKeys(BelongsToRelation $relation, RecordState $owner): void
	{
		foreach ($relation->getInnerKeys() as $innerField) {
			$owner->setValue((string) $innerField, null);
		}
	}

}
