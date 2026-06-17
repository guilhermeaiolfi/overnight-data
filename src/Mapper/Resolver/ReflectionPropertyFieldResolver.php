<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Resolver;

use ON\Data\Mapper\FieldContext;
use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\Support\ObjectPropertyMatcher;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use stdClass;

final class ReflectionPropertyFieldResolver implements FieldResolverInterface
{
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
		return $this->findTargetProperty($node) ?? $node->getSourceProperty();
	}

	private function findTargetProperty(MappingNode $node): ?ReflectionProperty
	{
		$target = $node->getParentTarget();
		if (! is_object($target) || $target instanceof stdClass) {
			return null;
		}

		$matcher = new ObjectPropertyMatcher(new ReflectionClass($target));

		return $matcher->match($node->getName());
	}
}
