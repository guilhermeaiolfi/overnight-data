<?php

declare(strict_types=1);

namespace ON\Data\Database\Sql\Dialect;

use ON\Data\Database\DatabaseFamily;

/** @internal */
final class PostgresDialect extends AbstractDialect
{
	public function family(): DatabaseFamily
	{
		return DatabaseFamily::PostgreSQL;
	}
}
