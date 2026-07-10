<?php

declare(strict_types=1);

namespace ON\Data\Query\QueryFunction\Standard\Temporal;

use ON\Data\Database\DatabaseFamily;

final class Day extends AbstractTemporalFunction
{
	protected function compileSql(DatabaseFamily $family, string $argumentSql): string
	{
		return match ($family) {
			DatabaseFamily::Sqlite => "CAST(strftime('%d', {$argumentSql}) AS INTEGER)",
			DatabaseFamily::PostgreSQL => "CAST(EXTRACT(DAY FROM {$argumentSql}) AS INTEGER)",
			DatabaseFamily::MySQL, DatabaseFamily::MariaDB => "DAY({$argumentSql})",
			default => $this->unsupported($family),
		};
	}
}
