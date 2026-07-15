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

	public static function constructorRequiresArguments(string $class): self
	{
		return new self(sprintf(
			'Object export requires a class with no required constructor arguments; "%s" was requested.',
			$class,
		));
	}

	public static function unknownProperty(string $class, string $property): self
	{
		return new self(sprintf(
			'Object export encountered unknown property "%s" for class "%s".',
			$property,
			$class,
		));
	}

	public static function mutableRequiresStdClass(string $class): self
	{
		return new self(sprintf(
			'Mutable query export currently supports stdClass only; "%s" was requested.',
			$class,
		));
	}

	public static function requiresObjectExport(): self
	{
		return new self('Mutable query export requires object export; call to(stdClass::class) before mutable().');
	}

	public static function mutableIterationUnsupported(): self
	{
		return new self('Mutable object export is not supported by iterate(); use fetchAll() or fetchOne().');
	}
}
