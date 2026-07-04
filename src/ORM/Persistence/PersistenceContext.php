<?php

declare(strict_types=1);

namespace ON\Data\ORM\Persistence;

use ON\Data\ORM\Relation\RelatedCollectionMap;
use ON\Data\ORM\Relation\RelatedReferenceMap;
use ON\Data\ORM\State\RecordStateMap;
use ON\Data\ORM\State\TrackedRepresentationMap;

final class PersistenceContext
{
	public function __construct(
		private RecordStateMap $records,
		private TrackedRepresentationMap $representations,
		private RelatedCollectionMap $relations,
		private RelatedReferenceMap $references,
		private CommandBuffer $commands,
	) {
	}

	public function getRecords(): RecordStateMap
	{
		return $this->records;
	}

	public function getRepresentations(): TrackedRepresentationMap
	{
		return $this->representations;
	}

	public function getRelations(): RelatedCollectionMap
	{
		return $this->relations;
	}

	public function getReferences(): RelatedReferenceMap
	{
		return $this->references;
	}

	public function getCommands(): CommandBuffer
	{
		return $this->commands;
	}
}
