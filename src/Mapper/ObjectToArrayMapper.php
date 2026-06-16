<?php

declare(strict_types=1);

namespace ON\Data\Mapper;

use BackedEnum;
use DateTimeInterface;
use ON\Data\Mapper\Attribute\Hidden;
use ON\Data\Mapper\Attribute\MapTo;
use ON\Data\Mapper\Representation\PhpRepresentation;
use ReflectionNamedType;
use ReflectionObject;
use ReflectionProperty;
use stdClass;

final class ObjectToArrayMapper extends Mapper
{
	public static function canMap(
		mixed $source,
		mixed $target,
		MappingContext $context,
	): bool {
		return is_object($source)
			&& ! $source instanceof stdClass
			&& ! $source instanceof DateTimeInterface
			&& ! $source instanceof BackedEnum
			&& is_array($target);
	}

	public function map(
		mixed $source,
		mixed $target,
		MappingContext $context,
	): array {
		$result = [];
		$reflection = new ReflectionObject($source);

		foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
			if ($property->isStatic() || ! $property->isInitialized($source) || $this->isHidden($property)) {
				continue;
			}

			$key = $this->resolveTargetKey($property);
			$value = $property->getValue($source);
			$field = $this->resolvePrimitiveField($property);

			if ($context->getOutputRepresentation() !== null && $field !== null) {
				$value = $this->gateway->to(
					PhpRepresentation::class,
					$value,
					$context->getOutputRepresentation(),
					$field,
				);
			}

			$result[$key] = $value;
		}

		return $result;
	}

	private function isHidden(ReflectionProperty $property): bool
	{
		return $property->getAttributes(Hidden::class) !== [];
	}

	private function resolveTargetKey(ReflectionProperty $property): string
	{
		$attributes = $property->getAttributes(MapTo::class);
		if ($attributes === []) {
			return $property->getName();
		}

		return $attributes[0]->newInstance()->getName();
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
}
