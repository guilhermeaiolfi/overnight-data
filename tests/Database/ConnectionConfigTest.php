<?php

declare(strict_types=1);

namespace Tests\ON\Data\Database;

use InvalidArgumentException;
use ON\Data\Database\ConnectionConfig;
use PDO;
use PHPUnit\Framework\TestCase;

final class ConnectionConfigTest extends TestCase
{
	public function testConfigCarriesPdoOptionsAndTablePrefix(): void
	{
		$config = new ConnectionConfig(
			dsn: 'sqlite::memory:',
			options: [PDO::ATTR_TIMEOUT => 10],
			tablePrefix: 'on_',
		);

		self::assertSame('sqlite::memory:', $config->dsn);
		self::assertSame([PDO::ATTR_TIMEOUT => 10], $config->options);
		self::assertSame('on_', $config->tablePrefix);
	}

	public function testConfigRequiresADsn(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('requires a PDO DSN');
		new ConnectionConfig(' ');
	}
}
