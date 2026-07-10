<?php

declare(strict_types=1);

namespace ON\Data\Query\QueryFunction\Standard\Temporal;

use ON\Data\Database\DatabaseFamily;

final class Year extends AbstractTemporalFunction
{
	protected function compileSql(DatabaseFamily $family, string $argumentSql): string
	{
		return match ($family) {
			DatabaseFamily::Sqlite => "CAST(strftime('%Y', {$argumentSql}) AS INTEGER)",
			DatabaseFamily::PostgreSQL => "CAST(EXTRACT(YEAR FROM {$argumentSql}) AS INTEGER)",
			DatabaseFamily::MySQL, DatabaseFamily::MariaDB => "YEAR({$argumentSql})",
			default => $this->unsupported($family),
		};
	}
}
