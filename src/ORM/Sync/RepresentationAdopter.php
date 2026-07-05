<?php

declare(strict_types=1);

namespace ON\Data\ORM\Sync;

use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RecordStateStore;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationState;
use ON\Data\ORM\State\RepresentationStore;

final class RepresentationAdopter
{
	public function __construct(
		private RecordStateStore $records,
		private RepresentationStore $representations,
	) {
	}

	public function adopt(
		object $representation,
		RepresentationBinding $binding,
		RecordState $record,
	): RepresentationState {
		if ($this->representations->has($representation)) {
			throw new SyncException('Cannot adopt representation because it is already tracked.');
		}

		$appliedBinding = $binding->applyToRecordState($record);
		$state = new RepresentationState(
			$appliedBinding,
			$this->buildBaselineRevisions($appliedBinding, $record)
		);

		$this->records->add($record);
		$this->representations->add($representation, $state);

		return $state;
	}

	/**
	 * @return array<string, int>
	 */
	private function buildBaselineRevisions(RepresentationBinding $binding, RecordState $record): array
	{
		$baselineRevisions = [];
		foreach ($binding->getFields() as $fieldBinding) {
			$recordHash = $fieldBinding->getField()->getRecordHash();
			if (! array_key_exists($recordHash, $baselineRevisions)) {
				$baselineRevisions[$recordHash] = $record->getRevision();
			}
		}

		return $baselineRevisions;
	}
}
