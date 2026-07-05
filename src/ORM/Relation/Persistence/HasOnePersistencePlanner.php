<?php

declare(strict_types=1);

namespace ON\Data\ORM\Relation\Persistence;

use ON\Data\Definition\Relation\BelongsToRelation;
use ON\Data\Definition\Relation\HasOneRelation;
use ON\Data\Definition\Relation\RelationInterface;
use ON\Data\ORM\Exception\RelationPersistenceException;
use ON\Data\ORM\Persistence\PersistenceContext;
use ON\Data\ORM\Relation\ToOneRelationState;
use ON\Data\ORM\Relation\RelationChangeInterface;
use ON\Data\ORM\State\RecordState;

final class HasOnePersistencePlanner implements RelationPersistencePlannerInterface
{
	public function plan(PersistenceContext $context, RelationInterface $relation, RelationChangeInterface $change): void
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

		if ($requiresUnlink && ! $relation->isNullable()) {
			throw new RelationPersistenceException(sprintf(
				"Relation '%s' on owner collection '%s' cannot unlink target by nulling outer keys because the relation is not nullable.",
				$relation->getName(),
				$owner->getCollectionName(),
			));
		}

		$currentRecord = $currentTarget === null
			? null
			: $this->resolveTargetRecord($context, $relation, $currentTarget);
		$baselineRecord = $requiresUnlink && $baselineTarget !== null
			? $this->resolveTargetRecord($context, $relation, $baselineTarget)
			: null;
		if ($baselineRecord instanceof RecordState) {
			$this->nullTargetOuterKeys($relation, $baselineRecord);
		}

		if ($currentRecord instanceof RecordState) {
			$this->copyOwnerKeysIntoTarget($relation, $owner, $currentRecord);
		}
	}

	private function resolveTargetRecord(PersistenceContext $context, HasOneRelation $relation, object $target): RecordState
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

	private function copyOwnerKeysIntoTarget(HasOneRelation $relation, RecordState $owner, RecordState $target): void
	{
		foreach ($relation->getInnerKeys() as $index => $innerField) {
			$innerField = (string) $innerField;
			$outerField = $relation->getOuterKeys()[$index] ?? null;
			if (! is_string($outerField) || $outerField === '') {
				throw new RelationPersistenceException(sprintf(
					"Relation '%s' is missing an outer key field for owner collection '%s' field '%s'.",
					$relation->getName(),
					$relation->getParent()->getName(),
					$innerField,
				));
			}

			$target->setValue($outerField, $owner->getValueRef($innerField));
		}
	}

	private function nullTargetOuterKeys(HasOneRelation $relation, RecordState $target): void
	{
		foreach ($relation->getOuterKeys() as $outerField) {
			$target->setValue((string) $outerField, null);
		}
	}

}
