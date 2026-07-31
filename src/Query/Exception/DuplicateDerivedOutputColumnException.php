<?php

declare(strict_types=1);

namespace ON\Data\Query\Exception;

use LogicException;
use ON\Data\Query\SelectQuery;

final class DuplicateDerivedOutputColumnException extends LogicException
{
	public static function forName(SelectQuery $query, string $name): self
	{
		return new self(sprintf(
			"Derived query for definition '%s' has duplicate output column '%s'; give colliding selections distinct aliases.",
			$query->getSourceName(),
			$name,
		));
	}
}
