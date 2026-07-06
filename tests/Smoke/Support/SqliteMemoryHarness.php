<?php

declare(strict_types=1);

namespace Tests\ON\Data\Smoke\Support;

use Cycle\Database\Config\DatabaseConfig;
use Cycle\Database\Config\SQLite\MemoryConnectionConfig;
use Cycle\Database\Config\SQLiteDriverConfig;
use Cycle\Database\DatabaseInterface;
use Cycle\Database\DatabaseManager;
use Cycle\Database\StatementInterface;
use ON\Data\Database\ConnectionConfig;
use ON\Data\Database\Cycle\CycleCommandExecutor;
use ON\Data\Database\Cycle\CycleQueryExecutor;
use ON\Data\Database\Database;
use ON\Data\Mapper\ConversionGateway;

/**
 * Shared in-memory SQLite setup for smoke tests.
 *
 * Uses the same ConnectionConfig::sqliteMemory() path as Database::connect().
 */
final class SqliteMemoryHarness
{
	private function __construct(
		public readonly DatabaseInterface $cycleDatabase,
		public readonly Database $database,
		public readonly CycleCommandExecutor $commandExecutor,
	) {
	}

	public static function create(): self
	{
		return self::fromConnectionConfig(ConnectionConfig::sqliteMemory());
	}

	public static function fromConnectionConfig(ConnectionConfig $config): self
	{
		$manager = new DatabaseManager(new DatabaseConfig([
			'default' => $config->databaseName,
			'databases' => [
				$config->databaseName => [
					'connection' => $config->connectionName,
					'prefix' => $config->tablePrefix,
				],
			],
			'connections' => [
				$config->connectionName => new SQLiteDriverConfig(
					connection: new MemoryConnectionConfig(),
					options: $config->options,
				),
			],
		]));

		$cycleDatabase = $manager->database($config->databaseName);
		$gateway = ConversionGateway::createDefault();

		return new self(
			$cycleDatabase,
			new Database(new CycleQueryExecutor($cycleDatabase, $gateway)),
			new CycleCommandExecutor($cycleDatabase),
		);
	}

	public function exec(string $sql): void
	{
		$this->cycleDatabase->execute($sql);
	}

	/**
	 * @param array<int|string, mixed> $parameters
	 *
	 * @return array<string, mixed>|null
	 */
	public function fetchRow(string $sql, array $parameters = []): ?array
	{
		$row = $this->cycleDatabase->query($sql, $parameters)->fetch(StatementInterface::FETCH_ASSOC);

		return is_array($row) ? $row : null;
	}
}
