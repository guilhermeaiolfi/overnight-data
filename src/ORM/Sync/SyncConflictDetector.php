<?php

declare(strict_types=1);

namespace ON\Data\ORM\Sync;

use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\State\RecordFieldRef;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\TrackedRepresentation;

final class SyncConflictDetector
{
	/**
	 * @param array<string, mixed> $currentValues
	 * @param callable(RecordFieldRef): RecordState $recordStateResolver
	 * @return list<SyncConflict>
	 */
	public function detect(
		TrackedRepresentation $tracked,
		array $currentValues,
		callable $recordStateResolver,
	): array {
		$conflicts = [];
		foreach ($tracked->getBinding()->getWritableFieldBindings() as $binding) {
			$path = $binding->getPath();
			if (! array_key_exists($path, $currentValues)) {
				throw new SyncException(sprintf("Current representation values do not contain path '%s'.", $path));
			}

			$field = $binding->getField();
			$recordState = $field->hasState() ? $field->getState() : $recordStateResolver($field);
			if (! $recordState instanceof RecordState) {
				throw new SyncException(sprintf("Record state resolver did not return a RecordState for path '%s'.", $path));
			}

			$fieldName = $field->getFieldName();
			$baselineRevision = $tracked->getBaselineRevisionFor($field);
			$baselineValue = $recordState->getHistory()->getValue($baselineRevision, $fieldName);
			$recordValue = $recordState->getValue($fieldName);
			$representationValue = $currentValues[$path];

			if ($representationValue === $baselineValue) {
				continue;
			}

			if ($recordState->getRevision() === $baselineRevision) {
				continue;
			}

			if ($recordValue === $baselineValue) {
				continue;
			}

			if ($recordValue === $representationValue) {
				continue;
			}

			$conflicts[] = new SyncConflict($path, $baselineValue, $recordValue, $representationValue);
		}

		return $conflicts;
	}
}
