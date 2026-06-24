<?php

declare(strict_types=1);

namespace ON\Data\Database\Exception;

use ON\Data\Query\SelectQuery;
use RuntimeException;
use Throwable;

final class QueryExecutionException extends RuntimeException
{
	public static function forQuery(SelectQuery $query, Throwable $previous): self
	{
		return new self(sprintf(
			"Failed to execute query for definition '%s'.",
			$query->getSource()->getName(),
		), 0, $previous);
	}
}
