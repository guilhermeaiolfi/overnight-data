<?php

declare(strict_types=1);

namespace ON\Data\ORM\Persistence;

use ON\Data\ORM\Relation\RelationStateStore;
use ON\Data\ORM\Relation\ToManyRelationState;
use ON\Data\ORM\Relation\ToOneRelationState;
use ON\Data\ORM\SessionContext;
use ON\Data\ORM\Record\RecordStateStore;
use ON\Data\ORM\Representation\State\RepresentationStateStore;

final class PersistenceContext
{
	public function __construct(
		private SessionContext $session,
		private CommandBuffer $commands,
	) {
	}

	public function getSession(): SessionContext
	{
		return $this->session;
	}

	public function getRecords(): RecordStateStore
	{
		return $this->session->getRecords();
	}

	public function getRepresentations(): RepresentationStateStore
	{
		return $this->session->getRepresentations();
	}

	/**
	 * @return RelationStateStore<ToManyRelationState>
	 */
	public function getToManyRelations(): RelationStateStore
	{
		return $this->session->getToManyRelations();
	}

	/**
	 * @return RelationStateStore<ToOneRelationState>
	 */
	public function getToOneRelations(): RelationStateStore
	{
		return $this->session->getToOneRelations();
	}

	public function getCommands(): CommandBuffer
	{
		return $this->commands;
	}
}
