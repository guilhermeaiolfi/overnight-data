<?php

declare(strict_types=1);

namespace ON\Data\Query\Result;

use ON\Data\Mapper\Exception\NoWriterFoundException;
use ON\Data\Mapper\MapperManager;
use ON\Data\Mapper\Mapping;
use ON\Data\Mapper\MappingOptions;
use ON\Data\Mapper\Writer\ObjectWriter;
use ON\Data\Query\Exception\ObjectExportException;
use ReflectionClass;
use ReflectionException;

final class ObjectExportClassValidator
{
	public static function assertSupported(string $class, ?MapperManager $manager = null): void
	{
		$manager ??= Mapping::getDefaultGateway()->getMapperManager();

		if (! $manager->canWrite($class)) {
			throw self::unsupportedTarget($class);
		}
	}

	public static function assertWritable(string $class, ?MapperManager $manager = null): void
	{
		$manager ??= Mapping::getDefaultGateway()->getMapperManager();

		try {
			$writer = $manager->resolveWriter($class, new MappingOptions($manager->getGateway()));
		} catch (NoWriterFoundException) {
			throw self::unsupportedTarget($class);
		}

		if ($writer instanceof ObjectWriter && ObjectWriter::isReadonlyTarget(new ReflectionClass($class))) {
			throw ObjectExportException::writableReadonlyNotSupported($class);
		}
	}

	private static function unsupportedTarget(string $class): ObjectExportException
	{
		try {
			$reflection = new ReflectionClass($class);
		} catch (ReflectionException) {
			return ObjectExportException::classNotFound($class);
		}

		return match (true) {
			$reflection->isTrait() => ObjectExportException::traitNotSupported($class),
			$reflection->isInterface() => ObjectExportException::interfaceNotSupported($class),
			$reflection->isAbstract() => ObjectExportException::abstractClassNotSupported($class),
			default => ObjectExportException::targetNotSupported($class),
		};
	}
}
