<?php

declare(strict_types=1);

namespace ON\Data\Query\QueryFunction;

use InvalidArgumentException;

final class FunctionArgumentException extends InvalidArgumentException
{
	public static function missing(int $index, int $count): self
	{
		return new self(sprintf(
			'Function argument at index %d is missing; %d argument(s) were provided.',
			$index,
			$count,
		));
	}

	public static function expectedLiteral(int $index, string $actualType): self
	{
		return new self(sprintf(
			'Function argument at index %d must be a literal expression, %s given.',
			$index,
			$actualType,
		));
	}

	public static function arity(string $function, int $expected, int $actual): self
	{
		return new self(sprintf(
			'Query function %s expects exactly %d argument(s), %d given.',
			$function,
			$expected,
			$actual,
		));
	}
}
