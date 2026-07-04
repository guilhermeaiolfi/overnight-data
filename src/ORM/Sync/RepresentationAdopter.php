<?php

declare(strict_types=1);

namespace ON\Data\ORM\Sync;

use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RecordStateMap;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\TrackedRepresentation;
use ON\Data\ORM\State\TrackedRepresentationMap;

final class RepresentationAdopter
{
	public function __construct(
		private RecordStateMap $records,
		private TrackedRepresentationMap $representations,
	) {
	}

	public function adopt(
		object $representation,
		RepresentationBinding $binding,
		RecordState $record,
	): TrackedRepresentation {
		if ($this->representations->has($representation)) {
			throw new SyncException('Cannot adopt representation because it is already tracked.');
		}

		$this->records->add($record);
		$appliedBinding = $binding->applyToRecordState($record);
		$tracked = new TrackedRepresentation(
			$representation,
			$appliedBinding,
			$this->buildBaselineRevisions($appliedBinding, $record)
		);

		$this->representations->add($tracked);

		return $tracked;
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
