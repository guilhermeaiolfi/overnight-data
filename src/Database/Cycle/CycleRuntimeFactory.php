<?php

declare(strict_types=1);

namespace ON\Data\Database\Cycle;

use Cycle\Database\DatabaseInterface as CycleDatabaseInterface;
use ON\Data\DataRuntime;
use ON\Data\Mapper\ConversionGateway;
use ON\Data\ORM\Persistence\CommandValueResolver;

final class CycleRuntimeFactory
{
	public function connect(
		ConnectionConfig $config,
		?ConversionGateway $gateway = null,
		?CommandValueResolver $commandValueResolver = null,
	): DataRuntime {
		return $this->create(
			(new CycleConnectionFactory())->createDatabase($config),
			$gateway,
			$commandValueResolver,
		);
	}

	public function create(
		CycleDatabaseInterface $database,
		?ConversionGateway $gateway = null,
		?CommandValueResolver $commandValueResolver = null,
	): DataRuntime {
		return new DataRuntime(
			new CycleQueryExecutor($database, $gateway ?? ConversionGateway::createDefault()),
			new CycleCommandExecutor($database, $commandValueResolver),
		);
	}
}
