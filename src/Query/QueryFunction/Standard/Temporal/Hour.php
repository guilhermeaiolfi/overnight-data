<?php

declare(strict_types=1);

namespace ON\Data\Query\QueryFunction\Standard\Temporal;

use ON\Data\Database\DatabaseFamily;

final class Hour extends AbstractTemporalFunction
{
	protected function compileSql(DatabaseFamily $family, string $argumentSql): string
	{
		return match ($family) {
			DatabaseFamily::Sqlite => "CAST(strftime('%H', {$argumentSql}) AS INTEGER)",
			DatabaseFamily::PostgreSQL => "CAST(EXTRACT(HOUR FROM {$argumentSql}) AS INTEGER)",
			DatabaseFamily::MySQL, DatabaseFamily::MariaDB => "HOUR({$argumentSql})",
			default => $this->unsupported($family),
		};
	}
}
