<?php

declare(strict_types=1);

namespace ON\Data\Database;

enum DatabaseFamily: string
{
	case Sqlite = 'sqlite';
	case PostgreSQL = 'postgresql';
	case MySQL = 'mysql';
	case MariaDB = 'mariadb';
	case SqlServer = 'sqlserver';
	case Other = 'other';

	public static function fromDriverType(string $type): self
	{
		$normalized = strtolower($type);

		return match (true) {
			str_contains($normalized, 'sqlite') => self::Sqlite,
			str_contains($normalized, 'postgres'),
			str_contains($normalized, 'pgsql') => self::PostgreSQL,
			str_contains($normalized, 'maria') => self::MariaDB,
			str_contains($normalized, 'mysql') => self::MySQL,
			str_contains($normalized, 'sqlserver'),
			str_contains($normalized, 'sqlsrv'),
			str_contains($normalized, 'mssql') => self::SqlServer,
			default => self::Other,
		};
	}
}
