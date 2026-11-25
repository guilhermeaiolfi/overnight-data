<?php

declare(strict_types=1);

namespace ON\Data\Database\Sql\Dialect;

use InvalidArgumentException;
use ON\Data\Database\ConnectionInterface;
use ON\Data\Database\DatabaseFamily;
use ON\Data\Database\Pdo\PdoConnection;

/** @internal */
final class DialectFactory
{
	public static function forConnection(ConnectionInterface $connection, ?bool $insertReturning = null): AbstractDialect
	{
		$version = $connection instanceof PdoConnection ? $connection->serverVersion() : null;
		$returning = $insertReturning ?? ($connection instanceof PdoConnection ? $connection->insertReturningOverride() : null);

		return match ($connection->family()) {
			DatabaseFamily::Sqlite => new SqliteDialect($returning ?? ($version === null || version_compare($version, '3.35.0', '>='))),
			DatabaseFamily::PostgreSQL => new PostgresDialect(),
			DatabaseFamily::MySQL => new MySqlDialect(),
			DatabaseFamily::MariaDB => new MySqlDialect(
				DatabaseFamily::MariaDB,
				$returning ?? self::mariaDbSupportsReturning($version),
			),
			DatabaseFamily::SqlServer => new SqlServerDialect($returning ?? true),
			default => throw new InvalidArgumentException('No SQL dialect for connection family ' . $connection->family()->value),
		};
	}

	private static function mariaDbSupportsReturning(?string $version): bool
	{
		return $version !== null
			&& preg_match('/^(?:5\.5\.5-)?([0-9]+\.[0-9]+\.[0-9]+)/', $version, $parts) === 1
			&& version_compare($parts[1], '10.5.0', '>=');
	}
}
