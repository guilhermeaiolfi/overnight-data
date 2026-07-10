<?php

declare(strict_types=1);

namespace ON\Data\Query\QueryFunction;

use RuntimeException;

final class FunctionCompilationException extends RuntimeException
{
	public static function recursion(string $function): self
	{
		return new self(sprintf(
			'Recursive compilation detected for query function %s.',
			$function,
		));
	}
}
