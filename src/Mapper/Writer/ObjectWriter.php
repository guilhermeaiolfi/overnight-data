<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Writer;

use ON\Data\Mapper\Exception\MappingException;
use ON\Data\Mapper\MappingContext;
use ON\Data\Mapper\Representation\RepresentationInterface;
use ON\Data\Mapper\Support\ObjectPropertyMatcher;
use ReflectionClass;
use ReflectionProperty;
use stdClass;
use Throwable;

final class ObjectWriter implements WriterInterface
{
	public static function canWrite(
		mixed $target,
		MappingContext $context,
	): bool {
		if ($target instanceof stdClass) {
			return true;
		}

		if (! is_string($target)) {
			return false;
		}

		return class_exists($target) || interface_exists($target) || enum_exists($target);
	}

	public function prepare(
		mixed $target,
		MappingContext $context,
	): object {
		if ($target instanceof stdClass) {
			return clone $target;
		}

		if ($target === stdClass::class) {
			return new stdClass();
		}

		$reflection = new ReflectionClass($target);
		$this->assertSupportedTarget($reflection);

		try {
			return $reflection->newInstanceWithoutConstructor();
		} catch (Throwable $exception) {
			throw new MappingException(
				sprintf("Unable to instantiate '%s' without calling its constructor.", $reflection->getName()),
				0,
				$exception,
			);
		}
	}

	public function write(
		mixed $target,
		string|int $name,
		mixed $value,
		MappingContext $context,
		mixed $walkerArguments = null,
	): object {
		if ($target instanceof stdClass) {
			$target->{(string) $name} = $value;

			return $target;
		}

		$matcher = new ObjectPropertyMatcher(new ReflectionClass($target));
		$property = $matcher->match($name);
		if ($property === null) {
			return $target;
		}

		try {
			$property->setValue($target, $value);
		} catch (Throwable $exception) {
			throw $this->wrapPropertyFailure(
				new ReflectionClass($target),
				$property,
				$context,
				$exception,
			);
		}

		return $target;
	}

	public function finish(
		mixed $target,
		MappingContext $context,
	): object {
		return $target;
	}

	private function assertSupportedTarget(ReflectionClass $reflection): void
	{
		$class = $reflection->getName();

		if ($reflection->isInterface()) {
			throw new MappingException(sprintf("Cannot map to interface target '%s'.", $class));
		}

		if ($reflection->isAbstract()) {
			throw new MappingException(sprintf("Cannot map to abstract target '%s'.", $class));
		}

		if ($reflection->isEnum()) {
			throw new MappingException(sprintf("Cannot map to enum target '%s'.", $class));
		}

		if (is_a($class, RepresentationInterface::class, true)) {
			throw new MappingException(sprintf("Cannot map to representation target '%s'.", $class));
		}

		if (method_exists($reflection, 'isReadOnly') && $reflection->isReadOnly()) {
			throw new MappingException(sprintf("Cannot map to readonly target '%s'.", $class));
		}

		foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
			if (! $property->isStatic() && $property->isReadOnly()) {
				throw new MappingException(
					sprintf("Cannot map to readonly property '%s::$%s'.", $class, $property->getName()),
				);
			}
		}
	}

	private function wrapPropertyFailure(
		ReflectionClass $reflection,
		ReflectionProperty $property,
		MappingContext $context,
		Throwable $exception,
	): MappingException {
		return new MappingException(
			sprintf(
				"Failed mapping '%s::$%s' at path '%s'.",
				$reflection->getName(),
				$property->getName(),
				$context->getPath(),
			),
			0,
			$exception,
		);
	}
}
