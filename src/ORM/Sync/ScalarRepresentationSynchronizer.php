<?php

declare(strict_types=1);

namespace ON\Data\ORM\Sync;

use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RecordStateStore;
use ON\Data\ORM\State\RepresentationState;
use ON\Data\ORM\State\RepresentationStore;

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
	public function sync(RepresentationStore $representations, RecordStateStore $records): array
	{
		$states = $representations->getAll();
		$planner = new SyncPlanner($this->reader, $this->conflicts, $records);
		$plans = [];
		$plannedStates = [];

		foreach ($states as $representation => $state) {
			$plans[] = $planner->plan($representation, $state);
			$plannedStates[] = $state;
		}

		$this->assertNoConflicts($plans);

		foreach ($plannedStates as $index => $state) {
			$this->applyPlan($state, $plans[$index]);
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

		$this->refreshBaselineRevisions($state, $touchedRecords);
	}

	/**
	 * @param array<string, RecordState> $touchedRecords
	 */
	private function refreshBaselineRevisions(RepresentationState $state, array $touchedRecords): void
	{
		if ($touchedRecords === []) {
			return;
		}

		$baselineRevisions = $state->getBaselineRevisions();
		foreach ($touchedRecords as $recordHash => $record) {
			$baselineRevisions[$recordHash] = $record->getRevision();
		}

		$state->replaceBaselineRevisions($baselineRevisions);
	}
}
