<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Sync;
use ON\Data\ORM\Record\RecordState;
use ON\Data\ORM\Record\RecordStateStore;
use ON\Data\ORM\Representation\Schema\RepresentationFieldSchema;
use ON\Data\ORM\Representation\Schema\RepresentationSchema;
use ON\Data\ORM\Representation\State\RepresentationState;
use ON\Data\ORM\Representation\State\RepresentationStateStore;
use ON\Data\ORM\Exception\SyncException;
final class RepresentationAdopter
{
	public function __construct(
		private RecordStateStore $records,
		private RepresentationStateStore $representations,
	) {
	}

	public function adopt(
		object $representation,
		RepresentationSchema $schema,
		RecordState $record,
	): RepresentationState {
		if ($this->representations->has($representation)) {
			throw new SyncException('Cannot adopt representation because it is already tracked.');
		}

		$state = RepresentationState::fromRecords($schema, [
			RepresentationFieldSchema::sourcePathKey([]) => $record,
		]);

		$this->records->add($record);
		$this->representations->add($representation, $state);

		return $state;
	}
}
