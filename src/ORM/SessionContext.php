<?php

declare(strict_types=1);

namespace ON\Data\ORM;

use ON\Data\ORM\Relation\ToManyRelationStore;
use ON\Data\ORM\Relation\ToOneRelationStore;
use ON\Data\ORM\State\RecordStateStore;
use ON\Data\ORM\State\RepresentationStore;

final class SessionContext
{
	private RecordStateStore $records;
	private RepresentationStore $representations;
	private ToManyRelationStore $relations;
	private ToOneRelationStore $references;

	public function __construct(
		?RecordStateStore $records = null,
		?RepresentationStore $representations = null,
		?ToManyRelationStore $relations = null,
		?ToOneRelationStore $references = null,
	) {
		$this->records = $records ?? new RecordStateStore();
		$this->representations = $representations ?? new RepresentationStore();
		$this->relations = $relations ?? new ToManyRelationStore();
		$this->references = $references ?? new ToOneRelationStore();
	}

	public function getRecords(): RecordStateStore
	{
		return $this->records;
	}

	public function getRepresentations(): RepresentationStore
	{
		return $this->representations;
	}

	public function getRelations(): ToManyRelationStore
	{
		return $this->relations;
	}

	public function getReferences(): ToOneRelationStore
	{
		return $this->references;
	}

	public function clear(): void
	{
		$this->records->clear();
		$this->representations->clear();
		$this->relations->clear();
		$this->references->clear();
	}
}
