<?php

declare(strict_types=1);

namespace ON\Data\Database\Exception;

use LogicException;
use ON\Data\Query\SelectQuery;

final class QueryNotExecutableException extends LogicException
{
	public static function forQuery(SelectQuery $query): self
	{
		return new self(sprintf(
			"Query for definition '%s' is not executable because no executor is bound.",
			$query->getSource()->getName(),
		));
	}
}
