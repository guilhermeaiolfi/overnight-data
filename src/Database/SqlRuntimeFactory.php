<?php

declare(strict_types=1);

namespace ON\Data\Database;

use ON\Data\Database\Pdo\PdoConnection;
use ON\Data\Database\Sql\Dialect\DialectFactory;
use ON\Data\Database\Sql\SqlCommandExecutor;
use ON\Data\Database\Sql\SqlQueryExecutor;
use ON\Data\DataRuntime;
use ON\Data\Mapper\ConversionGateway;
use ON\Data\ORM\Persistence\CommandValueResolver;
use ON\Data\ORM\Persistence\ConvertingCommandExecutor;
use PDO;

final class SqlRuntimeFactory
{
	public function __construct(private readonly ?bool $insertReturning = null)
	{
	}

	public function connect(
		ConnectionConfig $config,
		?ConversionGateway $gateway = null,
		?CommandValueResolver $resolver = null,
	): DataRuntime {
		return $this->create(PdoConnection::connect($config), $gateway, $resolver);
	}

	public function create(
		ConnectionInterface|PDO $connection,
		?ConversionGateway $gateway = null,
		?CommandValueResolver $resolver = null,
	): DataRuntime {
		if ($connection instanceof PDO) {
			$connection = new PdoConnection($connection);
		}

		$gateway ??= ConversionGateway::createDefault();
		$dialect = DialectFactory::forConnection($connection, $this->insertReturning);

		return new DataRuntime(
			new SqlQueryExecutor($connection, $gateway, $dialect),
			new ConvertingCommandExecutor(new SqlCommandExecutor($connection, $resolver, $dialect), $gateway, $resolver),
			$gateway,
		);
	}
}
