<?php

declare(strict_types=1);

namespace ON\Data\ORM\Sync;

use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\State\RecordStateStore;
use ON\Data\ORM\State\RepresentationState;
use ON\Data\ORM\State\RepresentationStateStore;

final class ScalarRepresentationSynchronizer
{
	private RepresentationReader $reader;
	private SyncConflictDetector $conflicts;

	public function __construct(
		?RepresentationReader $reader = null,
		?SyncConflictDetector $conflicts = null,
	) {
		$this->reader = $reader ?? new RepresentationReader();
		$this->conflicts = $conflicts ?? new SyncConflictDetector();
	}

	/**
	 * @return list<SyncPlan>
	 */
	public function sync(RepresentationStateStore $representations, RecordStateStore $records): array
	{
		$states = $representations->getAll();
		$plans = [];
		$plannedStates = [];

		foreach ($states as $representation => $state) {
			$plans[] = $this->buildPlan($representation, $state, $records);
			$plannedStates[] = $state;
		}

		$this->assertNoConflicts($plans);

		foreach ($plannedStates as $index => $state) {
			$this->applyPlan($state, $plans[$index]);
		}

		return $plans;
	}

	private function buildPlan(
		object $representation,
		RepresentationState $state,
		RecordStateStore $records,
	): SyncPlan {
		$currentValues = $this->readWritableValues($representation, $state);
		$conflicts = $this->conflicts->detect(
			$state,
			$currentValues,
		);

		return new SyncPlan(
			$this->buildUpdates($state, $records, $currentValues, $this->conflictPaths($conflicts)),
			$conflicts
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function readWritableValues(object $representation, RepresentationState $state): array
	{
		$values = [];

		foreach ($state->getWritableFieldItems() as $item) {
			$fieldBinding = $item->getBinding();

			try {
				$values[$item->getPath()] = $this->reader->readPath(
					$representation,
					$item->getPath()
				);
			} catch (SyncException $exception) {
				if ($fieldBinding->shouldSkipWhenMissing() && str_contains($exception->getMessage(), ' is missing.')) {
					continue;
				}

				throw $exception;
			}
		}

		return $values;
	}

	/**
	 * @param array<string, mixed> $currentValues
	 * @param array<string, true> $conflictPaths
	 *
	 * @return list<SyncFieldUpdate>
	 */
	private function buildUpdates(
		RepresentationState $state,
		RecordStateStore $records,
		array $currentValues,
		array $conflictPaths,
	): array {
		$updates = [];
		$updatesByTarget = [];

		foreach ($state->getWritableFieldItems() as $item) {
			$binding = $item->getBinding();
			$path = $item->getPath();
			if (isset($conflictPaths[$path])) {
				continue;
			}

			if (! array_key_exists($path, $currentValues)) {
				if ($binding->shouldSkipWhenMissing()) {
					continue;
				}

				throw new SyncException(sprintf("Current representation values do not contain path '%s'.", $path));
			}

			$record = $item->getRecord();
			$fieldName = $item->getFieldName();
			$currentValue = $currentValues[$path];
			if (! $item->hasBaselineValue()) {
				if ($currentValue === null && ! $item->hasCurrentRecordValue()) {
					continue;
				}

				$baselineValue = null;
			} else {
				$baselineValue = $item->getBaselineValue();
			}

			if ($currentValue === $baselineValue) {
				continue;
			}

			$target = $record->getStateHash() . "\0" . $fieldName;
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
	 *
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

	/**
	 * @param list<SyncPlan> $plans
	 */
	private function assertNoConflicts(array $plans): void
	{
		$count = 0;
		$firstPath = '';

		foreach ($plans as $plan) {
			foreach ($plan->getConflicts() as $conflict) {
				++$count;
				if ($firstPath === '') {
					$firstPath = $conflict->getPath();
				}
			}
		}

		if ($count === 0) {
			return;
		}

		throw new SyncException(sprintf(
			'Cannot synchronize representation states because %d conflict(s) were detected; first conflict at path \'%s\'.',
			$count,
			$firstPath
		));
	}

	private function applyPlan(RepresentationState $state, SyncPlan $plan): void
	{
		$touchedRecords = [];

		foreach ($plan->getUpdates() as $update) {
			$record = $update->getRecord();
			$record->setValue($update->getField(), $update->getValue());
			$touchedRecords[$record->getStateHash()] = $record;
		}

		$state->acceptSyncedRecords($touchedRecords);
	}
}
