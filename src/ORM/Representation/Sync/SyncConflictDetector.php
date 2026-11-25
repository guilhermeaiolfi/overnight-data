<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Sync;

use ON\Data\ORM\Representation\State\RepresentationState;

final class SyncConflictDetector
{
	/**
	 * @param array<string, mixed> $currentValues
	 * @return list<SyncConflict>
	 */
	public function detect(
		RepresentationState $tracked,
		array $currentValues,
	): array {
		$conflicts = [];
		foreach ($tracked->getWritableFields() as $field) {
			$path = $field->getPath();
			if (! array_key_exists($path, $currentValues) || ! $tracked->hasBaseline($path)) {
				continue;
			}

			$baselineValue = $tracked->getBaseline($path);
			$representationValue = $currentValues[$path];
			if ($representationValue === $baselineValue) {
				continue;
			}

			$record = $tracked->getRecordForField($field);
			$fieldName = $field->getFieldName();
			if (! $record->hasValue($fieldName)) {
				continue;
			}

			$recordValue = $record->getValue($fieldName);
			if ($recordValue === $baselineValue || $recordValue === $representationValue) {
				continue;
			}

			$conflicts[] = new SyncConflict($path, $baselineValue, $recordValue, $representationValue);
		}

		return $conflicts;
	}
}
