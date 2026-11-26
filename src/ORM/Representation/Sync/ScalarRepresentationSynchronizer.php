<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Sync;

use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\Representation\State\RepresentationState;

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
	 * @param iterable<object, RepresentationState> $representations
	 * @return list<SyncPlan>
	 */
	public function sync(
		iterable $representations,
		?RepresentationReadOperation $operation = null,
	): array {
		$reader = $this->reader->forOperation($operation);
		$plans = [];
		$plannedStates = [];
		$plannedValues = [];
		$updatesByTarget = [];

		foreach ($representations as $representation => $state) {
			$currentValues = $this->readWritableValues($reader->withObject($representation), $state);
			$plans[] = $this->buildPlan($state, $currentValues, $updatesByTarget);
			$plannedStates[] = $state;
			$plannedValues[] = $currentValues;
		}

		$this->assertNoConflicts($plans);

		foreach ($plannedStates as $index => $state) {
			$plans[$index]->apply($state, $plannedValues[$index]);
		}

		return $plans;
	}

	/**
	 * @param array<string, mixed> $currentValues
	 * @param array<string, SyncFieldUpdate> $updatesByTarget
	 */
	private function buildPlan(
		RepresentationState $state,
		array $currentValues,
		array &$updatesByTarget,
	): SyncPlan {
		$conflicts = $this->conflicts->detect(
			$state,
			$currentValues,
		);

		return new SyncPlan(
			$this->buildUpdates($state, $currentValues, $this->conflictPaths($conflicts), $updatesByTarget),
			$conflicts
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function readWritableValues(ObjectRepresentationReader $reader, RepresentationState $state): array
	{
		$values = [];

		foreach ($state->getWritableFields() as $fieldSchema) {
			$result = $reader->readPath($fieldSchema->getPath());
			if ($result->isMissing()) {
				continue;
			}

			$values[$fieldSchema->getPath()] = $result->getValue();
		}

		return $values;
	}

	/**
	 * @param array<string, mixed> $currentValues
	 * @param array<string, SyncFieldUpdate> $updatesByTarget
	 * @param array<string, true> $conflictPaths
	 *
	 * @return list<SyncFieldUpdate>
	 */
	private function buildUpdates(
		RepresentationState $state,
		array $currentValues,
		array $conflictPaths,
		array &$updatesByTarget,
	): array {
		$updates = [];

		foreach ($state->getWritableFields() as $fieldSchema) {
			$path = $fieldSchema->getPath();
			if (isset($conflictPaths[$path])) {
				continue;
			}

			if (! array_key_exists($path, $currentValues)) {
				continue;
			}

			$record = $state->getRecordForField($fieldSchema);
			$fieldName = $fieldSchema->getFieldName();
			$currentValue = $currentValues[$path];
			if ($currentValue === $state->getBaseline($path)) {
				continue;
			}

			$target = $record->getStateHash() . "\0" . $fieldName;
			if (array_key_exists($target, $updatesByTarget)) {
				$this->assertDuplicateTargetHasSameValue($updatesByTarget[$target], $currentValue, $path);
			}

			$update = new SyncFieldUpdate($record, $fieldName, $currentValue, $fieldSchema);
			$updates[$target] ??= $update;
			$updatesByTarget[$target] = $update;
		}

		return array_values($updates);
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
}
