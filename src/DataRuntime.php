<?php

declare(strict_types=1);

namespace ON\Data;

use ON\Data\Database\QueryExecutorInterface;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\ORM\Persistence\CommandExecutorInterface;
use ON\Data\Query\SelectQuery;

final class DataRuntime
{
	public function __construct(
		private readonly QueryExecutorInterface $queryExecutor,
		private readonly CommandExecutorInterface $commandExecutor,
	) {
	}

	public function query(CollectionInterface|SelectQuery $source): SelectQuery
	{
		return new SelectQuery($source, $this->queryExecutor);
	}

	public function getCommandExecutor(): CommandExecutorInterface
	{
		return $this->commandExecutor;
	}
}
