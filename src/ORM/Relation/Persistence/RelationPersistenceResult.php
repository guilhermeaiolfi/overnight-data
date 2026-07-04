<?php

declare(strict_types=1);

namespace ON\Data\ORM\Relation\Persistence;

use ON\Data\ORM\Persistence\CommandInterface;
use ON\Data\ORM\Relation\RelatedCollection;

final class RelationPersistenceResult
{
	/** @var list<RelatedCollection> */
	private array $collections;

	/** @var list<CommandInterface> */
	private array $commands;

	/**
	 * @param list<RelatedCollection> $collections
	 * @param list<CommandInterface> $commands
	 */
	public function __construct(array $collections, array $commands)
	{
		$this->collections = array_values($collections);
		$this->commands = array_values($commands);
	}

	/**
	 * @return list<RelatedCollection>
	 */
	public function getCollections(): array
	{
		return $this->collections;
	}

	/**
	 * @return list<CommandInterface>
	 */
	public function getCommands(): array
	{
		return $this->commands;
	}
}
