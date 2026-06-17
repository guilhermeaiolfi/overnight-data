<?php

declare(strict_types=1);

namespace ON\Data\Mapper;

use ReflectionNamedType;
use ReflectionProperty;

final class ReflectionPropertyFieldContextResolver implements FieldContextResolverInterface
{
	public function resolve(
		mixed $source,
		MappingContext $context,
	): ?FieldContext {
		if (! $source instanceof ReflectionProperty) {
			return null;
		}

		$type = $source->getType();
		if (! $type instanceof ReflectionNamedType) {
			return null;
		}

		if (! $type->isBuiltin() || ! in_array($type->getName(), ['string', 'int', 'bool', 'float'], true)) {
			return null;
		}

		return FieldContext::named(
			$source->getName(),
			$type->getName(),
			$type->allowsNull(),
		);
	}
}
