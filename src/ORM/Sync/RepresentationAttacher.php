<?php

declare(strict_types=1);

namespace ON\Data\ORM\Sync;

use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RecordStateStore;
use ON\Data\ORM\State\RepresentationSchema;
use ON\Data\ORM\State\RepresentationState;
use ON\Data\ORM\State\RepresentationStateStore;

final class RepresentationAttacher
{
	public function __construct(
		private RepresentationStateFactory $stateFactory = new RepresentationStateFactory(),
	) {
	}

	/**
	 * @param array<string, RecordState> $recordsBySourceKey
	 */
	public function attach(
		object $representation,
		RepresentationSchema $schema,
		array $recordsBySourceKey,
		RecordStateStore $records,
		RepresentationStateStore $representations,
		RepresentationAttachmentMode $mode = RepresentationAttachmentMode::Add,
	): RepresentationState {
		if ($mode === RepresentationAttachmentMode::Add && $representations->has($representation)) {
			throw new SyncException('Cannot adopt representation because it is already tracked.');
		}

		$state = $this->stateFactory->fromRecords($schema, $recordsBySourceKey);

		foreach ($this->uniqueRecords($recordsBySourceKey) as $record) {
			$records->add($record);
		}

		if ($mode === RepresentationAttachmentMode::Replace && $representations->has($representation)) {
			$representations->remove($representation);
		}

		$representations->add($representation, $state);

		return $state;
	}

	/**
	 * @param array<string, RecordState> $recordsBySourceKey
	 * @return list<RecordState>
	 */
	private function uniqueRecords(array $recordsBySourceKey): array
	{
		$records = [];
		foreach ($recordsBySourceKey as $record) {
			$records[spl_object_id($record)] = $record;
		}

		return array_values($records);
	}
}
