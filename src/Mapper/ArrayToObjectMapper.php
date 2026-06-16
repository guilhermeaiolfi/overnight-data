<?php

declare(strict_types=1);

namespace ON\Data\Mapper;

use ON\Data\Mapper\Attribute\MapFrom;
use ON\Data\Mapper\Exception\MappingException;
use ON\Data\Mapper\Representation\PhpRepresentation;
use ON\Data\Mapper\Representation\RepresentationInterface;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use stdClass;
use Throwable;

final class ArrayToObjectMapper extends Mapper
{
	public static function canMap(
		mixed $source,
		mixed $target,
		MappingContext $context,
	): bool {
		if (! is_array($source) || ! is_string($target) || $target === stdClass::class) {
			return false;
		}

		return class_exists($target)
			|| interface_exists($target)
			|| enum_exists($target);
	}

	public function map(
		mixed $source,
		mixed $target,
		MappingContext $context,
	): object {
		$reflection = $this->reflectTarget($target);
		$this->assertSupportedTarget($reflection);

		try {
			$result = $reflection->newInstanceWithoutConstructor();
		} catch (Throwable $exception) {
			throw new MappingException(
				sprintf("Unable to instantiate '%s' without calling its constructor.", $reflection->getName()),
				0,
				$exception,
			);
		}

		foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
			if ($property->isStatic()) {
				continue;
			}

			$sourceKey = $this->resolveSourceKey($property);
			if (! array_key_exists($sourceKey, $source)) {
				continue;
			}

			$propertyContext = $context->withPathSegment($property->getName());

			try {
				$value = $this->normalizeInboundValue($source[$sourceKey], $property, $context);
				$property->setValue($result, $value);
			} catch (MappingException $exception) {
				throw $this->wrapPropertyFailure($reflection, $property, $propertyContext, $exception);
			} catch (Throwable $exception) {
				throw $this->wrapPropertyFailure($reflection, $property, $propertyContext, $exception);
			}
		}

		return $result;
	}

	private function reflectTarget(string $target): ReflectionClass
	{
		return new ReflectionClass($target);
	}

	private function assertSupportedTarget(ReflectionClass $reflection): void
	{
		$class = $reflection->getName();

		if ($reflection->isInterface()) {
			throw new MappingException(sprintf("Cannot map arrays to interface target '%s'.", $class));
		}

		if ($reflection->isAbstract()) {
			throw new MappingException(sprintf("Cannot map arrays to abstract target '%s'.", $class));
		}

		if ($reflection->isEnum()) {
			throw new MappingException(sprintf("Cannot map arrays to enum target '%s'.", $class));
		}

		if (is_a($class, RepresentationInterface::class, true)) {
			throw new MappingException(sprintf("Cannot map arrays to representation target '%s'.", $class));
		}

		if ($this->isReadonlyClass($reflection)) {
			throw new MappingException(sprintf("Cannot map arrays to readonly target '%s'.", $class));
		}

		foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
			if (! $property->isStatic() && $property->isReadOnly()) {
				throw new MappingException(
					sprintf("Cannot map arrays to readonly property '%s::$%s'.", $class, $property->getName())
				);
			}
		}
	}

	private function isReadonlyClass(ReflectionClass $reflection): bool
	{
		return method_exists($reflection, 'isReadOnly') && $reflection->isReadOnly();
	}

	private function resolveSourceKey(ReflectionProperty $property): string
	{
		$attributes = $property->getAttributes(MapFrom::class);
		if ($attributes === []) {
			return $property->getName();
		}

		return $attributes[0]->newInstance()->getName();
	}

	private function normalizeInboundValue(
		mixed $value,
		ReflectionProperty $property,
		MappingContext $context,
	): mixed {
		$representation = $context->getSourceRepresentation();
		if ($representation === null) {
			return $value;
		}

		$field = $this->resolvePrimitiveField($property);
		if ($field === null) {
			return $value;
		}

		return $this->gateway->to(
			$representation,
			$value,
			PhpRepresentation::class,
			$field,
		);
	}

	private function resolvePrimitiveField(ReflectionProperty $property): ?FieldContext
	{
		$type = $property->getType();
		if (! $type instanceof ReflectionNamedType) {
			return null;
		}

		if (! $type->isBuiltin() || ! in_array($type->getName(), ['string', 'int', 'bool', 'float'], true)) {
			return null;
		}

		return FieldContext::named(
			$property->getName(),
			$type->getName(),
			$type->allowsNull(),
		);
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
