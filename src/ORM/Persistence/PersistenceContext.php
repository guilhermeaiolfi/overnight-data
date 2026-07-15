<?php

declare(strict_types=1);

namespace ON\Data\ORM\Persistence;

use ON\Data\ORM\Record\RecordStateStore;
use ON\Data\ORM\Relation\RelationStateStore;
use ON\Data\ORM\Representation\State\RepresentationStateStore;
use ON\Data\ORM\SessionContext;

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

	public function getRelations(): RelationStateStore
	{
		return $this->session->getRelations();
	}

	public function getCommands(): CommandBuffer
	{
		return $this->commands;
	}
}
