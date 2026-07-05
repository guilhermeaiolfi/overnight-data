<?php

declare(strict_types=1);

namespace ON\Data\ORM\Persistence;

use ON\Data\ORM\Relation\ToManyRelationStore;
use ON\Data\ORM\Relation\ToOneRelationStore;
use ON\Data\ORM\State\RecordStateStore;
use ON\Data\ORM\State\RepresentationStore;

final class PersistenceContext
{
	public function __construct(
		private RecordStateStore $records,
		private RepresentationStore $representations,
		private ToManyRelationStore $relations,
		private ToOneRelationStore $references,
		private CommandBuffer $commands,
	) {
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

	public function getCommands(): CommandBuffer
	{
		return $this->commands;
	}
}
