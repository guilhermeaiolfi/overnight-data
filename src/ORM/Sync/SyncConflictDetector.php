<?php

declare(strict_types=1);

namespace ON\Data\ORM\Sync;

use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\State\RepresentationState;

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
		foreach ($tracked->getWritableFieldItems() as $item) {
			$binding = $item->getBinding();
			$path = $item->getPath();
			if (! array_key_exists($path, $currentValues)) {
				if ($binding->shouldSkipWhenMissing()) {
					continue;
				}

				throw new SyncException(sprintf("Current representation values do not contain path '%s'.", $path));
			}

			$recordState = $item->getRecord();
			$fieldName = $item->getFieldName();
			$baselineRevision = $item->getBaselineRevision();
			if (! $item->hasBaselineValue()) {
				continue;
			}

			$baselineValue = $item->getBaselineValue();
			$recordValue = $item->getCurrentRecordValue();
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
