<?php

declare(strict_types=1);

namespace ON\Data\Database\Exception;

use LogicException;
use ON\Data\Query\SelectQuery;

final class UnsupportedQueryException extends LogicException
{
	public static function forQuery(SelectQuery $query, string $reason): self
	{
		return new self(sprintf(
			"Query for definition '%s' is unsupported: %s",
			$query->getSourceName(),
			trim($reason),
		));
	}
}
