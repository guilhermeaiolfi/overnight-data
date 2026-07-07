<?php

declare(strict_types=1);

namespace Tests\ON\Data\Database;

use Cycle\Database\Config\DatabaseConfig;
use Cycle\Database\Config\SQLite\MemoryConnectionConfig;
use Cycle\Database\Config\SQLiteDriverConfig;
use Cycle\Database\DatabaseInterface;
use Cycle\Database\DatabaseManager;
use ON\Data\Database\ConnectionConfig;
use ON\Data\Database\Cycle\CycleCommandExecutor;
use ON\Data\Database\Cycle\CycleQueryExecutor;
use ON\Data\Database\DataRuntime;
use ON\Data\Definition\Registry;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

#[RequiresPhpExtension('pdo_sqlite')]
final class DataRuntimeTest extends TestCase
{
	public function testConnectWithSqliteMemoryAllowsQuerying(): void
	{
		$runtime = DataRuntime::connect(ConnectionConfig::sqliteMemory());
		$runtime->getCommandExecutor();

		$database = $this->databaseFromRuntimeExecutor($runtime, 'commandExecutor');
		$database->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
		$database->execute("INSERT INTO users (id, name) VALUES (1, 'Ada')");

		$users = (new Registry())
			->collection('users')
			->table('users')
			->primaryKey('id')
			->field('id', 'int')->end()
			->field('name', 'string')->end();

		$query = $runtime->query($users);

		self::assertSame(
			[['id' => 1, 'name' => 'Ada']],
			$query->select($query->id, $query->name)->fetchAll(),
		);
	}

	public function testFromCycleUsesExactDatabaseForQueryExecution(): void
	{
		$database = $this->cycleDatabase();
		$runtime = DataRuntime::fromCycle($database);
		$queryExecutor = $this->runtimeProperty($runtime, 'queryExecutor');

		self::assertInstanceOf(CycleQueryExecutor::class, $queryExecutor);
		self::assertSame($database, $this->executorDatabase($queryExecutor));
	}

	public function testFromCycleWiresCommandExecutorFromSameDatabase(): void
	{
		$database = $this->cycleDatabase();
		$runtime = DataRuntime::fromCycle($database);
		$commandExecutor = $runtime->getCommandExecutor();

		self::assertInstanceOf(CycleCommandExecutor::class, $commandExecutor);
		self::assertSame($database, $this->executorDatabase($commandExecutor));
	}

	private function cycleDatabase(): DatabaseInterface
	{
		$manager = new DatabaseManager(new DatabaseConfig([
			'default' => 'default',
			'databases' => [
				'default' => ['connection' => 'sqlite'],
			],
			'connections' => [
				'sqlite' => new SQLiteDriverConfig(
					connection: new MemoryConnectionConfig(),
				),
			],
		]));

		return $manager->database('default');
	}

	private function databaseFromRuntimeExecutor(DataRuntime $runtime, string $property): DatabaseInterface
	{
		$executor = $this->runtimeProperty($runtime, $property);

		return $this->executorDatabase($executor);
	}

	private function runtimeProperty(DataRuntime $runtime, string $property): object
	{
		$reflection = new ReflectionClass($runtime);
		$value = $reflection->getProperty($property)->getValue($runtime);

		self::assertIsObject($value);

		return $value;
	}

	private function executorDatabase(object $executor): DatabaseInterface
	{
		$property = new ReflectionProperty($executor, 'database');
		$database = $property->getValue($executor);

		self::assertInstanceOf(DatabaseInterface::class, $database);

		return $database;
	}
}
