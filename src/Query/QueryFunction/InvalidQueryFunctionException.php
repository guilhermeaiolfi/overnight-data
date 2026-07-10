<?php

declare(strict_types=1);

namespace ON\Data\Query\QueryFunction;

use InvalidArgumentException;

final class InvalidQueryFunctionException extends InvalidArgumentException
{
	public static function classMissing(string $function): self
	{
		return new self(sprintf('Query function class "%s" does not exist.', $function));
	}

	public static function mustImplement(string $function): self
	{
		return new self(sprintf(
			'Query function class "%s" must implement %s.',
			$function,
			QueryFunctionInterface::class,
		));
	}

	public static function notInstantiable(string $function): self
	{
		return new self(sprintf(
			'Query function class "%s" must be instantiable with no required constructor parameters.',
			$function,
		));
	}
}
