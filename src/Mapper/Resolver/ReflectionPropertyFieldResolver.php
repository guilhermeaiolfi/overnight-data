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
	private readonly MappingNodePropertyFinder $propertyFinder;

	public function __construct()
	{
		$this->propertyFinder = new MappingNodePropertyFinder();
	}

	public function resolve(MappingNode $node): ?FieldContext
	{
		$property = $this->findProperty($node);
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

	private function findProperty(MappingNode $node): ?ReflectionProperty
	{
		return $this->propertyFinder->findTargetProperty($node)
			?? $this->propertyFinder->findSourceProperty($node);
	}
}
