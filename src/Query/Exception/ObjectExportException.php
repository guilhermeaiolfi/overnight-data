<?php

declare(strict_types=1);

namespace ON\Data\Query\Exception;

use InvalidArgumentException;

final class ObjectExportException extends InvalidArgumentException
{
	public static function unsupportedClass(string $class): self
	{
		return new self(sprintf(
			'Object export currently supports stdClass only; "%s" was requested.',
			$class,
		));
	}
}
