<?php

declare(strict_types=1);

namespace ON\Data\Query\QueryFunction\Standard\Temporal;

use ON\Data\Database\DatabaseFamily;

final class DateValue extends AbstractTemporalFunction
{
	protected function compileSql(DatabaseFamily $family, string $argumentSql): string
	{
		return match ($family) {
			DatabaseFamily::Sqlite => "date({$argumentSql})",
			DatabaseFamily::PostgreSQL => "CAST({$argumentSql} AS DATE)",
			DatabaseFamily::MySQL, DatabaseFamily::MariaDB => "DATE({$argumentSql})",
			default => $this->unsupported($family),
		};
	}
}
