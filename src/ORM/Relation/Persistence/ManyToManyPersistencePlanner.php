<?php

declare(strict_types=1);

namespace ON\Data\ORM\Relation\Persistence;

use ON\Data\Definition\Relation\M2MRelation;
use ON\Data\Definition\Relation\RelationInterface;
use ON\Data\ORM\Exception\RelationPersistenceException;
use ON\Data\ORM\Persistence\DeleteCommand;
use ON\Data\ORM\Persistence\InsertCommand;
use ON\Data\ORM\Persistence\PersistenceContext;
use ON\Data\ORM\Relation\RelatedCollection;
use ON\Data\ORM\State\RecordState;

final class ManyToManyPersistencePlanner implements RelationPersistencePlannerInterface
{
	public function plan(PersistenceContext $context, RelationInterface $relation, RelatedCollection $collection): void
	{
		if (! $relation instanceof M2MRelation) {
			throw new RelationPersistenceException(sprintf(
				"Relation '%s' must be a many-to-many relation to use %s.",
				$collection->getRelationName(),
				self::class,
			));
		}

		$throughCollection = $relation->getThrough()->getCollection();
		$owner = $collection->getOwner();

		foreach ($collection->getAdded() as $item) {
			$target = $this->resolveTargetRecord($context, $relation, $item);
			$context->getCommands()->add(new InsertCommand(
				$throughCollection,
				$this->buildThroughValues($relation, $owner, $target),
			));
		}

		foreach ($collection->getRemoved() as $item) {
			$target = $this->resolveTargetRecord($context, $relation, $item);
			$context->getCommands()->add(new DeleteCommand(
				$throughCollection,
				$this->buildThroughValues($relation, $owner, $target),
			));
		}
	}

	private function resolveTargetRecord(PersistenceContext $context, RelationInterface $relation, object $item): RecordState
	{
		$tracked = $context->getRepresentations()->get($item);
		if ($tracked === null) {
			throw new RelationPersistenceException(sprintf(
				"Relation '%s' target item is not tracked.",
				$relation->getName(),
			));
		}

		$target = $context->getRecords()->getFromRepresentation($tracked);
		if (! $target instanceof RecordState) {
			throw new RelationPersistenceException(sprintf(
				"Relation '%s' tracked target item cannot be resolved to a record state.",
				$relation->getName(),
			));
		}

		return $target;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function buildThroughValues(M2MRelation $relation, RecordState $owner, RecordState $target): array
	{
		return array_replace(
			$this->copyMappedValues(
				$relation->getInnerKeys(),
				$relation->getThrough()->getInnerKeys(),
				$owner,
				$relation->getName(),
				'owner',
			),
			$this->copyMappedValues(
				$relation->getOuterKeys(),
				$relation->getThrough()->getOuterKeys(),
				$target,
				$relation->getName(),
				'target',
			),
		);
	}

	/**
	 * @param array<int, string> $sourceFields
	 * @param array<int, string> $targetFields
	 * @return array<string, mixed>
	 */
	private function copyMappedValues(
		array $sourceFields,
		array $targetFields,
		RecordState $source,
		string $relationName,
		string $side,
	): array {
		$values = [];
		foreach ($sourceFields as $index => $sourceField) {
			$sourceField = (string) $sourceField;
			$targetField = $targetFields[$index] ?? null;
			if (! is_string($targetField) || $targetField === '') {
				throw new RelationPersistenceException(sprintf(
					"Relation '%s' through mapping is missing a target field for %s field '%s'.",
					$relationName,
					$side,
					$sourceField,
				));
			}

			$values[$targetField] = $this->requireAvailableValue($source, $sourceField, $relationName, $side);
		}

		return $values;
	}

	private function requireAvailableValue(RecordState $source, string $fieldName, string $relationName, string $side): mixed
	{
		if (! $source->hasValue($fieldName)) {
			throw new RelationPersistenceException(sprintf(
				"Relation '%s' cannot build through row: %s collection '%s' is missing required field '%s'.",
				$relationName,
				$side,
				$source->getCollectionName(),
				$fieldName,
			));
		}

		$value = $source->getValue($fieldName);
		if ($value === null) {
			throw new RelationPersistenceException(sprintf(
				"Relation '%s' cannot build through row: %s collection '%s' has null required field '%s'.",
				$relationName,
				$side,
				$source->getCollectionName(),
				$fieldName,
			));
		}

		return $value;
	}
}
