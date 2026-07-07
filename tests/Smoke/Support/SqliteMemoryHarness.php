<?php

declare(strict_types=1);

namespace Tests\ON\Data\Smoke\Support;

use Cycle\Database\DatabaseInterface;
use Cycle\Database\StatementInterface;
use ON\Data\Database\Cycle\ConnectionConfig;
use ON\Data\Database\Cycle\CycleCommandExecutor;
use ON\Data\Database\Cycle\CycleConnectionFactory;
use ON\Data\Database\Cycle\CycleRuntimeFactory;
use ON\Data\DataRuntime;

/**
 * Shared in-memory SQLite setup for smoke tests.
 *
 * Uses the same ConnectionConfig::dsn('sqlite', 'sqlite::memory:') path as CycleRuntimeFactory::connect().
 */
final class SqliteMemoryHarness
{
	private function __construct(
		public readonly DatabaseInterface $cycleDatabase,
		public readonly DataRuntime $database,
		public readonly CycleCommandExecutor $commandExecutor,
	) {
	}

	public static function create(): self
	{
		return self::fromConnectionConfig(ConnectionConfig::dsn('sqlite', 'sqlite::memory:'));
	}

	public static function fromConnectionConfig(ConnectionConfig $config): self
	{
		$cycleDatabase = (new CycleConnectionFactory())->createDatabase($config);

		return new self(
			$cycleDatabase,
			(new CycleRuntimeFactory())->create($cycleDatabase),
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
