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

	public static function requiresObjectExport(): self
	{
		return new self('Mutable query export requires object export; call to(stdClass::class) before mutable().');
	}

	public static function requiresMutableSession(): self
	{
		return new self('Mutable query export requires an explicit Session; call mutable($session) before fetchAll() or fetchOne().');
	}

	public static function mutableIterationUnsupported(): self
	{
		return new self('Mutable object export is not supported by iterate(); use fetchAll() or fetchOne().');
	}
}
