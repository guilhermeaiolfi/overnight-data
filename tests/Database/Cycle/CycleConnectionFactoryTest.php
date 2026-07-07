<?php

declare(strict_types=1);

namespace Tests\ON\Data\Database\Cycle;

use Cycle\Database\DatabaseInterface;
use Cycle\Database\DatabaseManager;
use InvalidArgumentException;
use ON\Data\Database\ConnectionConfig;
use ON\Data\Database\Cycle\CycleConnectionFactory;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[RequiresPhpExtension('pdo_sqlite')]
final class CycleConnectionFactoryTest extends TestCase
{
	public function testCreateManagerReturnsCycleDatabaseManager(): void
	{
		$manager = (new CycleConnectionFactory())->createManager(ConnectionConfig::dsn('sqlite', 'sqlite::memory:'));

		self::assertInstanceOf(DatabaseManager::class, $manager);
	}

	public function testCreateDatabaseReturnsCycleDatabaseInterface(): void
	{
		$database = (new CycleConnectionFactory())->createDatabase(ConnectionConfig::dsn('sqlite', 'sqlite::memory:'));

		self::assertInstanceOf(DatabaseInterface::class, $database);
	}

	public function testSqliteConfigWithoutDsnThrowsClearException(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage("ConnectionConfig for driver 'sqlite' requires a DSN.");

		(new CycleConnectionFactory())->createDatabase(new ConnectionConfig(driver: 'sqlite'));
	}

	public function testFactoryOnlyCreatesCycleInfrastructure(): void
	{
		$reflection = new ReflectionClass(CycleConnectionFactory::class);
		$fileName = $reflection->getFileName();

		self::assertIsString($fileName);
		$source = (string) file_get_contents($fileName);

		foreach ([
			'CycleQueryExecutor',
			'CycleCommandExecutor',
			'SelectQuery',
			'DataRuntime',
			'QueryExecutorInterface',
			'CommandExecutorInterface',
			'Session',
			'FlushExecutor',
			'Registry',
			'CollectionInterface',
		] as $forbidden) {
			self::assertStringNotContainsString($forbidden, $source);
		}
	}
}
