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
	 * @param RelationStateStore<ToManyRelationState> $toManyRelations
	 * @param RelationStateStore<ToOneRelationState> $toOneRelations
	 */
	public function __construct(
		private RecordStateStore $records,
		private RepresentationStore $representations,
		private RelationStateStore $toManyRelations,
		private RelationStateStore $toOneRelations,
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
	public function getToManyRelations(): RelationStateStore
	{
		return $this->toManyRelations;
	}

	/**
	 * @return RelationStateStore<ToOneRelationState>
	 */
	public function getToOneRelations(): RelationStateStore
	{
		return $this->toOneRelations;
	}

	public function getCommands(): CommandBuffer
	{
		return $this->commands;
	}
}
