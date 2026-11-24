<?php

declare(strict_types=1);

namespace ON\Data\Database\Sql\Dialect;

use ON\Data\Database\DatabaseFamily;

/** @internal */
final class SqliteDialect extends AbstractDialect
{
	protected const UNLIMITED = 'LIMIT -1';

	public function __construct(private readonly bool $returning = true)
	{
	}

	public function family(): DatabaseFamily
	{
		return DatabaseFamily::Sqlite;
	}

	public function supportsInsertReturning(): bool
	{
		return $this->returning;
	}

	public function sqliteChangesQuery(): string
	{
		return 'SELECT CHANGES() AS affected';
	}
}
