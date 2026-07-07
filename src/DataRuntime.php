<?php

declare(strict_types=1);

namespace ON\Data;

use Cycle\Database\DatabaseInterface as CycleDatabaseInterface;
use ON\Data\Database\ConnectionConfig;
use ON\Data\Database\Cycle\CycleCommandExecutor;
use ON\Data\Database\Cycle\CycleConnectionFactory;
use ON\Data\Database\Cycle\CycleQueryExecutor;
use ON\Data\Database\QueryExecutorInterface;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Mapper\ConversionGateway;
use ON\Data\ORM\Persistence\CommandExecutorInterface;
use ON\Data\ORM\Persistence\CommandValueResolver;
use ON\Data\Query\SelectQuery;

final class DataRuntime
{
	public function __construct(
		private readonly QueryExecutorInterface $queryExecutor,
		private readonly CommandExecutorInterface $commandExecutor,
	) {
	}

	public static function connect(
		ConnectionConfig $config,
		?ConversionGateway $gateway = null,
		?CommandValueResolver $commandValueResolver = null,
	): self {
		$database = (new CycleConnectionFactory())->createDatabase($config);

		return self::fromCycle($database, $gateway, $commandValueResolver);
	}

	public static function fromCycle(
		CycleDatabaseInterface $database,
		?ConversionGateway $gateway = null,
		?CommandValueResolver $commandValueResolver = null,
	): self {
		return new self(
			new CycleQueryExecutor($database, $gateway ?? ConversionGateway::createDefault()),
			new CycleCommandExecutor($database, $commandValueResolver),
		);
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
