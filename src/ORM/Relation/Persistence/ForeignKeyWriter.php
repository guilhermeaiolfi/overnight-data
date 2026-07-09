<?php

declare(strict_types=1);

namespace ON\Data\ORM\Relation\Persistence;

use ON\Data\ORM\Exception\RelationPersistenceException;
use ON\Data\ORM\Record\RecordState;

final class ForeignKeyWriter
{
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
		foreach ($sourceFields as $index => $sourceField) {
			$sourceField = (string) $sourceField;
			$targetField = $targetFields[$index] ?? null;
			if (! is_string($targetField) || $targetField === '') {
				throw $missingTargetField($relationName, $sourceField, $index);
			}

			$target->setValue($targetField, $source->getValueRef($sourceField));
		}
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
