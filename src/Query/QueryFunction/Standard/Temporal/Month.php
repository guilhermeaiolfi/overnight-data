<?php

declare(strict_types=1);

namespace ON\Data\Query\QueryFunction\Standard\Temporal;

use ON\Data\Database\DatabaseFamily;

final class Month extends AbstractTemporalFunction
{
	protected function compileSql(DatabaseFamily $family, string $argumentSql): string
	{
		return match ($family) {
			DatabaseFamily::Sqlite => "CAST(strftime('%m', {$argumentSql}) AS INTEGER)",
			DatabaseFamily::PostgreSQL => "CAST(EXTRACT(MONTH FROM {$argumentSql}) AS INTEGER)",
			DatabaseFamily::MySQL, DatabaseFamily::MariaDB => "MONTH({$argumentSql})",
			default => $this->unsupported($family),
		};
	}
}
