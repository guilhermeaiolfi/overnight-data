<?php

declare(strict_types=1);

namespace ON\Data\ORM\Persistence;

use ON\Data\ORM\Relation\RelationStateStore;
use ON\Data\ORM\Relation\ToManyRelationState;
use ON\Data\ORM\Relation\ToOneRelationState;
use ON\Data\ORM\State\RecordStateStore;
use ON\Data\ORM\State\RepresentationStore;

final class PersistenceContext
{
	/**
	 * @param RelationStateStore<ToManyRelationState> $relations
	 * @param RelationStateStore<ToOneRelationState> $references
	 */
	public function __construct(
		private RecordStateStore $records,
		private RepresentationStore $representations,
		private RelationStateStore $relations,
		private RelationStateStore $references,
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

	/**
	 * @return RelationStateStore<ToManyRelationState>
	 */
	public function getRelations(): RelationStateStore
	{
		return $this->relations;
	}

	/**
	 * @return RelationStateStore<ToOneRelationState>
	 */
	public function getReferences(): RelationStateStore
	{
		return $this->references;
	}

	public function getCommands(): CommandBuffer
	{
		return $this->commands;
	}
}
