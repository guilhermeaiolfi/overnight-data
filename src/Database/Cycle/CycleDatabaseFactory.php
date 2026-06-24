<?php

declare(strict_types=1);

namespace ON\Data\Database\Cycle;

use Cycle\Database\Config\DatabaseConfig;
use Cycle\Database\Config\MySQL\DsnConnectionConfig as MySqlDsnConnectionConfig;
use Cycle\Database\Config\MySQLDriverConfig;
use Cycle\Database\Config\Postgres\DsnConnectionConfig as PostgresDsnConnectionConfig;
use Cycle\Database\Config\PostgresDriverConfig;
use Cycle\Database\Config\SQLite\DsnConnectionConfig as SqliteDsnConnectionConfig;
use Cycle\Database\Config\SQLite\MemoryConnectionConfig;
use Cycle\Database\Config\SQLiteDriverConfig;
use Cycle\Database\Config\SQLServer\DsnConnectionConfig as SqlServerDsnConnectionConfig;
use Cycle\Database\Config\SQLServerDriverConfig;
use Cycle\Database\DatabaseManager;
use InvalidArgumentException;
use ON\Data\Database\ConnectionConfig;
use ON\Data\Database\Database;
use ON\Data\Mapper\ConversionGateway;

final class CycleDatabaseFactory
{
	public function create(
		ConnectionConfig $config,
		?ConversionGateway $gateway = null,
	): Database {
		$manager = new DatabaseManager(new DatabaseConfig([
			'default' => $config->databaseName,
			'databases' => [
				$config->databaseName => [
					'connection' => $config->connectionName,
					'prefix' => $config->tablePrefix,
				],
			],
			'connections' => [
				$config->connectionName => $this->driverConfig($config),
			],
		]));

		return new Database(new CycleQueryExecutor(
			$manager->database($config->databaseName),
			$gateway ?? ConversionGateway::createDefault(),
		));
	}

	private function driverConfig(ConnectionConfig $config): SQLiteDriverConfig|MySQLDriverConfig|PostgresDriverConfig|SQLServerDriverConfig
	{
		$driver = strtolower($config->driver);

		return match ($driver) {
			'sqlite' => new SQLiteDriverConfig(
				connection: $config->sqliteMemory
					? new MemoryConnectionConfig()
					: new SqliteDsnConnectionConfig($this->requireDsn($config)),
				options: $config->options,
			),
			'mysql' => new MySQLDriverConfig(
				connection: new MySqlDsnConnectionConfig(
					$this->requireDsn($config),
					$config->username,
					$config->password,
					$config->options,
				),
			),
			'pgsql', 'postgres', 'postgresql' => new PostgresDriverConfig(
				connection: new PostgresDsnConnectionConfig(
					$this->requireDsn($config),
					$config->username,
					$config->password,
					$config->options,
				),
				schema: $config->schema,
			),
			'sqlserver', 'sqlsrv' => new SQLServerDriverConfig(
				connection: new SqlServerDsnConnectionConfig(
					$this->requireDsn($config),
					$config->username,
					$config->password,
					$config->options,
				),
			),
			default => throw new InvalidArgumentException(sprintf(
				"Unsupported Cycle driver '%s'.",
				$config->driver,
			)),
		};
	}

	private function requireDsn(ConnectionConfig $config): string
	{
		if ($config->dsn === null || trim($config->dsn) === '') {
			throw new InvalidArgumentException(sprintf(
				"ConnectionConfig for driver '%s' requires a DSN.",
				$config->driver,
			));
		}

		return $config->dsn;
	}
}
