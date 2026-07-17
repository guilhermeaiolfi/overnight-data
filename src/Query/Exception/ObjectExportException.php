<?php

declare(strict_types=1);

namespace ON\Data\Query\Exception;

use InvalidArgumentException;

final class ObjectExportException extends InvalidArgumentException
{
	public static function classNotFound(string $class): self
	{
		return new self(sprintf(
			'Object export class "%s" does not exist.',
			$class,
		));
	}

	public static function interfaceNotSupported(string $class): self
	{
		return new self(sprintf(
			'Object export does not support interfaces; "%s" was requested.',
			$class,
		));
	}

	public static function traitNotSupported(string $class): self
	{
		return new self(sprintf(
			'Object export does not support traits; "%s" was requested.',
			$class,
		));
	}

	public static function abstractClassNotSupported(string $class): self
	{
		return new self(sprintf(
			'Object export does not support abstract classes; "%s" was requested.',
			$class,
		));
	}

	public static function writableRequiresStdClass(string $class): self
	{
		return new self(sprintf(
			'Writable query export currently supports stdClass only; "%s" was requested.',
			$class,
		));
	}

	public static function requiresObjectExport(): self
	{
		return new self('Writable query export requires object export; call to(stdClass::class) before writable().');
	}

	public static function writableIterationUnsupported(): self
	{
		return new self('Writable object export is not supported by iterate(); use fetchAll() or fetchOne().');
	}
}
