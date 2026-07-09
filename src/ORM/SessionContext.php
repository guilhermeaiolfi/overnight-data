<?php

declare(strict_types=1);

namespace ON\Data\ORM;

use ON\Data\ORM\Relation\RelationStateStore;
use ON\Data\ORM\Record\RecordStateStore;
use ON\Data\ORM\Representation\State\RepresentationStateStore;
use ON\Data\ORM\Representation\Sync\ExistingIntentStore;

final class SessionContext
{
	private RecordStateStore $records;
	private RepresentationStateStore $representations;
	private ExistingIntentStore $existingIntents;

	private RelationStateStore $relations;

	public function __construct(
		?RecordStateStore $records = null,
		?RepresentationStateStore $representations = null,
		?RelationStateStore $relations = null,
	) {
		$this->records = $records ?? new RecordStateStore();
		$this->representations = $representations ?? new RepresentationStateStore();
		$this->existingIntents = new ExistingIntentStore();
		$this->relations = $relations ?? new RelationStateStore();
	}

	public function getExistingIntents(): ExistingIntentStore
	{
		return $this->existingIntents;
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

	public function clear(): void
	{
		$this->records->clear();
		$this->representations->clear();
		$this->existingIntents->clear();
		$this->relations->clear();
	}
}
