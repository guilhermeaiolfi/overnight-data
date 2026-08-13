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
		foreach ($tracked->getWritableFieldItems() as $item) {
			$path = $item->getPath();
			if (! array_key_exists($path, $currentValues)) {
				continue;
			}

			$recordState = $item->getRecord();
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
