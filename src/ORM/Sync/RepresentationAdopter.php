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
		private RepresentationStateFactory $stateFactory = new RepresentationStateFactory(),
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

		$state = $this->stateFactory->fromRootRecord($binding, $record);

		$this->records->add($record);
		$this->representations->add($representation, $state);

		return $state;
	}
}
