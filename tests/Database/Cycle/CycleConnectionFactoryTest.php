<?php

declare(strict_types=1);

namespace Tests\ON\Data\Database\Cycle;

use Cycle\Database\DatabaseInterface;
use Cycle\Database\DatabaseManager;
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
		$manager = (new CycleConnectionFactory())->createManager(ConnectionConfig::sqliteMemory());

		self::assertInstanceOf(DatabaseManager::class, $manager);
	}

	public function testCreateReturnsCycleDatabaseInterface(): void
	{
		$database = (new CycleConnectionFactory())->create(ConnectionConfig::sqliteMemory());

		self::assertInstanceOf(DatabaseInterface::class, $database);
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
		] as $forbidden) {
			self::assertStringNotContainsString($forbidden, $source);
		}
	}
}
