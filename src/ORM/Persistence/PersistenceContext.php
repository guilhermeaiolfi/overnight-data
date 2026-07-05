<?php

declare(strict_types=1);

namespace ON\Data\ORM\Persistence;

use ON\Data\ORM\Relation\RelatedCollectionStore;
use ON\Data\ORM\Relation\RelatedReferenceStore;
use ON\Data\ORM\State\RecordStateStore;
use ON\Data\ORM\State\RepresentationStore;

final class PersistenceContext
{
	public function __construct(
		private RecordStateStore $records,
		private RepresentationStore $representations,
		private RelatedCollectionStore $relations,
		private RelatedReferenceStore $references,
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

	public function getRelations(): RelatedCollectionStore
	{
		return $this->relations;
	}

	public function getReferences(): RelatedReferenceStore
	{
		return $this->references;
	}

	public function getCommands(): CommandBuffer
	{
		return $this->commands;
	}
}
