<?php

declare(strict_types=1);

namespace ON\Data\Query\Result;

use ON\Data\Query\Exception\ObjectExportException;
use ReflectionClass;
use ReflectionProperty;
use stdClass;

final class ObjectExportClassValidator
{
	public static function assertSupported(string $class): void
	{
		if ($class === stdClass::class) {
			return;
		}

		if (trait_exists($class)) {
			throw ObjectExportException::traitNotSupported($class);
		}

		if (interface_exists($class)) {
			throw ObjectExportException::interfaceNotSupported($class);
		}

		if (! class_exists($class)) {
			throw ObjectExportException::classNotFound($class);
		}

		$reflection = new ReflectionClass($class);

		if ($reflection->isTrait()) {
			throw ObjectExportException::traitNotSupported($class);
		}

		if ($reflection->isInterface()) {
			throw ObjectExportException::interfaceNotSupported($class);
		}

		if ($reflection->isAbstract()) {
			throw ObjectExportException::abstractClassNotSupported($class);
		}
	}

	/**
	 * Writable export: stdClass or a concrete non-readonly class (mutable public-property bag).
	 */
	public static function assertWritable(string $class): void
	{
		self::assertSupported($class);

		if ($class === stdClass::class) {
			return;
		}

		$reflection = new ReflectionClass($class);
		if (self::isReadonlyTarget($reflection)) {
			throw ObjectExportException::writableReadonlyNotSupported($class);
		}
	}

	/**
	 * @param ReflectionClass<object> $reflection
	 */
	private static function isReadonlyTarget(ReflectionClass $reflection): bool
	{
		if (method_exists($reflection, 'isReadOnly') && $reflection->isReadOnly()) {
			return true;
		}

		foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
			if (! $property->isStatic() && $property->isReadOnly()) {
				return true;
			}
		}

		return false;
	}
}
