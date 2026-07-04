<?php

declare(strict_types=1);

namespace ON\Data\ORM\Relation\Persistence;

use ON\Data\ORM\Persistence\CommandInterface;
use ON\Data\ORM\Relation\RelationChangeInterface;

final class RelationPersistenceResult
{
	/** @var list<RelationChangeInterface> */
	private array $changes;

	/** @var list<CommandInterface> */
	private array $commands;

	/**
	 * @param list<RelationChangeInterface> $changes
	 * @param list<CommandInterface> $commands
	 */
	public function __construct(array $changes, array $commands)
	{
		$this->changes = array_values($changes);
		$this->commands = array_values($commands);
	}

	/**
	 * @return list<RelationChangeInterface>
	 */
	public function getChanges(): array
	{
		return $this->changes;
	}

	/**
	 * @return list<CommandInterface>
	 */
	public function getCommands(): array
	{
		return $this->commands;
	}
}
