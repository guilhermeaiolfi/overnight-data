<?php

declare(strict_types=1);

namespace ON\Data\ORM\Relation\Persistence;

use ON\Data\Definition\Relation\RelationInterface;
use ON\Data\ORM\Exception\RelationPersistenceException;
use ON\Data\ORM\Record\RecordState;

final class ForeignKeyWriter
{
	public function copyOwnerKeys(RelationInterface $relation, RecordState $owner, RecordState $target): void
	{
		$ownerCollection = $owner->getCollection()->getName();
		$this->copyValues(
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

	/**
	 * @param array<int|string, mixed> $sourceFields
	 * @param array<int|string, mixed> $targetFields
	 * @param callable(string $relationName, string $sourceField, int|string $index): RelationPersistenceException $missingTargetField
	 */
	public function copyValues(
		string $relationName,
		array $sourceFields,
		array $targetFields,
		RecordState $source,
		RecordState $target,
		callable $missingTargetField,
	): void {
		$target->setValues($this->buildValues($relationName, $sourceFields, $targetFields, $source, $missingTargetField));
	}

	/**
	 * @param array<int|string, mixed> $targetFields
	 */
	public function nullValues(RecordState $target, array $targetFields): void
	{
		foreach ($targetFields as $targetField) {
			$target->setValue((string) $targetField, null);
		}
	}

	/**
	 * @param array<int|string, mixed> $sourceFields
	 * @param array<int|string, mixed> $targetFields
	 * @param callable(string $relationName, string $sourceField, int|string $index): RelationPersistenceException $missingTargetField
	 * @return array<string, mixed>
	 */
	public function buildValues(
		string $relationName,
		array $sourceFields,
		array $targetFields,
		RecordState $source,
		callable $missingTargetField,
	): array {
		$values = [];
		foreach ($sourceFields as $index => $sourceField) {
			$sourceField = (string) $sourceField;
			$targetField = $targetFields[$index] ?? null;
			if (! is_string($targetField) || $targetField === '') {
				throw $missingTargetField($relationName, $sourceField, $index);
			}

			$values[$targetField] = $source->getValueRef($sourceField);
		}

		return $values;
	}
}
