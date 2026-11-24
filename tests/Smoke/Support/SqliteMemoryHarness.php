<?php

declare(strict_types=1);

namespace Tests\ON\Data\Smoke\Support;

use ON\Data\Database\ConnectionConfig;
use ON\Data\Database\Pdo\PdoConnection;
use ON\Data\Database\SqlRuntimeFactory;
use ON\Data\DataRuntime;
use ON\Data\ORM\Persistence\CommandExecutorInterface;

final class SqliteMemoryHarness
{
	private function __construct(
		public readonly PdoConnection $connection,
		public readonly DataRuntime $database,
		public readonly CommandExecutorInterface $commandExecutor,
	) {
	}

	public static function create(): self
	{
		$connection = PdoConnection::connect(new ConnectionConfig('sqlite::memory:'));
		$runtime = (new SqlRuntimeFactory())->create($connection);

		return new self(
			$connection,
			$runtime,
			$runtime->getCommandExecutor(),
		);
	}

	public function exec(string $sql): void
	{
		$this->connection->execute($sql);
	}

	/**
	 * @param list<mixed> $parameters
	 *
	 * @return array<string, mixed>|null
	 */
	public function fetchRow(string $sql, array $parameters = []): ?array
	{
		return $this->connection->fetchOne($sql, $parameters);
	}
}
