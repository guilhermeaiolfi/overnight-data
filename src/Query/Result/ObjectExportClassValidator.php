<?php

declare(strict_types=1);

namespace ON\Data\Query\Result;

use ON\Data\Query\Exception\ObjectExportException;
use ReflectionClass;
use stdClass;

final class ObjectExportClassValidator
{
	public static function assertSupported(string $class): void
	{
		if ($class === stdClass::class) {
			return;
		}

		if (trait_exists($class, autoload: false)) {
			throw ObjectExportException::traitNotSupported($class);
		}

		if (interface_exists($class, autoload: false)) {
			throw ObjectExportException::interfaceNotSupported($class);
		}

		if (! class_exists($class, autoload: false)) {
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

		$constructor = $reflection->getConstructor();

		if ($constructor !== null) {
			foreach ($constructor->getParameters() as $parameter) {
				if (! $parameter->isOptional()) {
					throw ObjectExportException::constructorRequiresArguments($class);
				}
			}
		}
	}
}
