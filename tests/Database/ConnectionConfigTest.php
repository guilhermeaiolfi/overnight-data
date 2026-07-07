<?php

declare(strict_types=1);

namespace Tests\ON\Data\Database;

use ON\Data\Database\ConnectionConfig;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ConnectionConfigTest extends TestCase
{
	public function testConnectionConfigDoesNotExposeSqliteMemoryState(): void
	{
		$reflection = new ReflectionClass(ConnectionConfig::class);

		self::assertFalse($reflection->hasProperty('sqliteMemory'));
		self::assertFalse($reflection->hasMethod('sqliteMemory'));
	}

	public function testDsnConstructorKeepsGenericConnectionFields(): void
	{
		$config = ConnectionConfig::dsn(
			driver: 'sqlite',
			dsn: 'sqlite::memory:',
			databaseName: 'app',
			connectionName: 'main',
			tablePrefix: 'on_',
		);

		self::assertSame('sqlite', $config->driver);
		self::assertSame('sqlite::memory:', $config->dsn);
		self::assertSame('app', $config->databaseName);
		self::assertSame('main', $config->connectionName);
		self::assertSame('on_', $config->tablePrefix);
	}
}
