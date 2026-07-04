<?php

declare(strict_types=1);

namespace ON\Data\ORM\Sync;

use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RecordStateMap;
use ON\Data\ORM\State\TrackedRepresentation;
use ON\Data\ORM\State\TrackedRepresentationMap;

final class ScalarRepresentationSynchronizer
{
	private RepresentationValueReader $reader;
	private SyncConflictDetector $conflicts;

	public function __construct(
		?RepresentationValueReader $reader = null,
		?SyncConflictDetector $conflicts = null,
	) {
		$this->reader = $reader ?? new RepresentationValueReader();
		$this->conflicts = $conflicts ?? new SyncConflictDetector();
	}

	/**
	 * @return list<SyncPlan>
	 */
	public function sync(TrackedRepresentationMap $representations, RecordStateMap $records): array
	{
		$trackedRepresentations = $representations->getAll();
		$planner = new SyncPlanner($this->reader, $this->conflicts, $records);
		$plans = [];

		foreach ($trackedRepresentations as $tracked) {
			$plans[] = $planner->plan($tracked);
		}

		$this->assertNoConflicts($plans);

		foreach ($trackedRepresentations as $index => $tracked) {
			$this->applyPlan($tracked, $plans[$index]);
		}

		return $plans;
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
			'Cannot synchronize tracked representations because %d conflict(s) were detected; first conflict at path \'%s\'.',
			$count,
			$firstPath
		));
	}

	private function applyPlan(TrackedRepresentation $tracked, SyncPlan $plan): void
	{
		$touchedRecords = [];

		foreach ($plan->getUpdates() as $update) {
			$record = $update->getRecord();
			$record->setValue($update->getField(), $update->getValue());
			$touchedRecords[$record->getStateHash()] = $record;
		}

		$this->refreshBaselineRevisions($tracked, $touchedRecords);
	}

	/**
	 * @param array<string, RecordState> $touchedRecords
	 */
	private function refreshBaselineRevisions(TrackedRepresentation $tracked, array $touchedRecords): void
	{
		if ($touchedRecords === []) {
			return;
		}

		$baselineRevisions = $tracked->getBaselineRevisions();
		foreach ($touchedRecords as $recordHash => $record) {
			$baselineRevisions[$recordHash] = $record->getRevision();
		}

		$tracked->replaceBaselineRevisions($baselineRevisions);
	}
}
