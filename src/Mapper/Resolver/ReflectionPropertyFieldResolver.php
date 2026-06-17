<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Resolver;

use ON\Data\Mapper\FieldContext;
use ON\Data\Mapper\MappingContext;
use ON\Data\Mapper\Support\ObjectPropertyMatcher;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use stdClass;

final class ReflectionPropertyFieldResolver implements FieldResolverInterface
{
	public function resolve(
		MappingContext $mapping,
		string $path,
		string|int $fieldName,
		mixed $value,
		mixed $extra = null,
	): ?FieldContext {
		$property = $this->findProperty($fieldName, $mapping->getTarget(), $extra);
		if (! $property instanceof ReflectionProperty) {
			return null;
		}

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

	private function findProperty(
		string|int $fieldName,
		mixed $target,
		mixed $extra,
	): ?ReflectionProperty {
		$property = $this->extractReflectionProperty($extra);
		if ($property instanceof ReflectionProperty) {
			return $property;
		}

		if (! is_object($target) || $target instanceof stdClass) {
			return null;
		}

		$matcher = new ObjectPropertyMatcher(new ReflectionClass($target));

		return $matcher->match($fieldName);
	}

	private function extractReflectionProperty(mixed $extra): ?ReflectionProperty
	{
		if ($extra instanceof ReflectionProperty) {
			return $extra;
		}

		if (! is_array($extra)) {
			return null;
		}

		foreach ($extra as $value) {
			$property = $this->extractReflectionProperty($value);
			if ($property instanceof ReflectionProperty) {
				return $property;
			}
		}

		return null;
	}
}
