<?php

declare(strict_types=1);

namespace ON\Data\ORM;

use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\Record\RecordStateStore;
use ON\Data\ORM\Relation\RelationStateStore;
use ON\Data\ORM\Representation\State\RepresentationState;
use ON\Data\ORM\Representation\State\RepresentationStateStore;
use ON\Data\ORM\Representation\Sync\RepresentationAttachmentMode;
use ON\Data\ORM\Representation\Sync\RepresentationIntentStore;

final class SessionContext
{
	private RecordStateStore $records;
	private RepresentationStateStore $representations;
	private RepresentationIntentStore $intents;
	private RelationStateStore $relations;

	public function __construct(
		?RecordStateStore $records = null,
		?RepresentationStateStore $representations = null,
		?RelationStateStore $relations = null,
		?RepresentationIntentStore $intents = null,
	) {
		$this->records = $records ?? new RecordStateStore();
		$this->representations = $representations ?? new RepresentationStateStore();
		$this->intents = $intents ?? new RepresentationIntentStore();
		$this->relations = $relations ?? new RelationStateStore();
	}

	public function getIntents(): RepresentationIntentStore
	{
		return $this->intents;
	}

	public function getRecords(): RecordStateStore
	{
		return $this->records;
	}

	public function getRepresentations(): RepresentationStateStore
	{
		return $this->representations;
	}

	public function getRelations(): RelationStateStore
	{
		return $this->relations;
	}

	public function attachRepresentation(
		object $representation,
		RepresentationState $state,
		RepresentationAttachmentMode $mode = RepresentationAttachmentMode::Add,
	): RepresentationState {
		$state->getSchema()->assertWritable();
		if ($mode === RepresentationAttachmentMode::Add && $this->representations->has($representation)) {
			throw new SyncException('Cannot attach representation because it is already tracked.');
		}

		foreach ($state->getUniqueRecords() as $record) {
			$this->records->add($record);
		}
		if ($mode === RepresentationAttachmentMode::Replace) {
			$this->representations->remove($representation);
		}
		$this->representations->add($representation, $state);

		return $state;
	}

	public function clear(): void
	{
		$this->records->clear();
		$this->representations->clear();
		$this->intents->clear();
		$this->relations->clear();
	}
}
