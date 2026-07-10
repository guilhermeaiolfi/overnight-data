<?php

declare(strict_types=1);

namespace ON\Data\Query\QueryFunction;

use ON\Data\Database\DatabaseFamily;
use RuntimeException;

final class UnsupportedQueryFunctionException extends RuntimeException
{
	public static function forPlatform(string $function, DatabaseFamily $family): self
	{
		return new self(sprintf(
			'Query function %s is not supported on database platform %s.',
			$function,
			$family->value,
		));
	}
}
