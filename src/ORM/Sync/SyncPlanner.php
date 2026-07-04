<?php

declare(strict_types=1);

namespace ON\Data\ORM\Sync;

use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RecordStateMap;
use ON\Data\ORM\State\RepresentationFieldBinding;
use ON\Data\ORM\State\TrackedRepresentation;

final class SyncPlanner
{
	public function __construct(
		private RepresentationValueReader $reader,
		private SyncConflictDetector $conflicts,
		private RecordStateMap $records,
	) {
	}

	public function plan(TrackedRepresentation $tracked): SyncPlan
	{
		$currentValues = $this->reader->read(
			$tracked->getRepresentation(),
			$tracked->getBinding()
		);
		$conflicts = $this->conflicts->detect(
			$tracked,
			$currentValues,
			$this->records->requireForField(...)
		);

		return new SyncPlan(
			$this->buildUpdates($tracked, $currentValues, $this->conflictPaths($conflicts)),
			$conflicts
		);
	}

	/**
	 * @param array<string, mixed> $currentValues
	 * @param array<string, true> $conflictPaths
	 * @return list<SyncFieldUpdate>
	 */
	private function buildUpdates(TrackedRepresentation $tracked, array $currentValues, array $conflictPaths): array
	{
		$updates = [];
		$updatesByTarget = [];

		foreach ($tracked->getBinding()->getWritableFieldBindings() as $binding) {
			$path = $binding->getPath();
			if (isset($conflictPaths[$path])) {
				continue;
			}

			if (! array_key_exists($path, $currentValues)) {
				throw new SyncException(sprintf("Current representation values do not contain path '%s'.", $path));
			}

			$record = $this->recordFor($binding);
			$field = $binding->getField();
			$fieldName = $field->getFieldName();
			$baselineRevision = $tracked->getBaselineRevisionFor($field);
			$baselineValue = $record->getHistory()->getValue($baselineRevision, $fieldName);
			$currentValue = $currentValues[$path];
			if ($currentValue === $baselineValue) {
				continue;
			}

			$target = $field->getRecordHash() . "\0" . $fieldName;
			if (array_key_exists($target, $updatesByTarget)) {
				$this->assertDuplicateTargetHasSameValue($updatesByTarget[$target], $currentValue, $path);

				continue;
			}

			$update = new SyncFieldUpdate($record, $fieldName, $currentValue, $binding);
			$updates[] = $update;
			$updatesByTarget[$target] = $update;
		}

		return $updates;
	}

	private function recordFor(RepresentationFieldBinding $binding): RecordState
	{
		return $this->records->requireForField($binding->getField());
	}

	private function assertDuplicateTargetHasSameValue(SyncFieldUpdate $existing, mixed $currentValue, string $path): void
	{
		if ($existing->getValue() === $currentValue) {
			return;
		}

		throw new SyncException(sprintf(
			"Cannot plan sync update for path '%s' because multiple values target record field '%s'.",
			$path,
			$existing->getField()
		));
	}

	/**
	 * @param list<SyncConflict> $conflicts
	 * @return array<string, true>
	 */
	private function conflictPaths(array $conflicts): array
	{
		$paths = [];
		foreach ($conflicts as $conflict) {
			$paths[$conflict->getPath()] = true;
		}

		return $paths;
	}
}
