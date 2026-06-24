<?php

declare(strict_types=1);

namespace ON\Data\Query\Exception;

use InvalidArgumentException;

final class UnknownQueryExpressionException extends InvalidArgumentException
{
	public static function forQuery(string $name, string $sourceName): self
	{
		return new self(sprintf(
			"Unknown query expression '%s' on definition '%s'.",
			$name,
			$sourceName,
		));
	}
}
