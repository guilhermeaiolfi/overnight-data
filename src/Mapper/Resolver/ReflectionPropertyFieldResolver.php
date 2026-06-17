<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Resolver;

use ON\Data\Mapper\FieldContext;
use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\Support\MappingNodePropertyFinder;
use ReflectionNamedType;
use ReflectionProperty;

final class ReflectionPropertyFieldResolver implements FieldResolverInterface
{
	public function __construct(
		private readonly ?MappingNodePropertyFinder $propertyFinder = null,
	) {
	}

	public function resolve(MappingNode $node): ?FieldContext
	{
		$finder = $this->propertyFinder ?? new MappingNodePropertyFinder();
		$property = $finder->findTargetProperty($node) ?? $finder->findSourceProperty($node);
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
}
