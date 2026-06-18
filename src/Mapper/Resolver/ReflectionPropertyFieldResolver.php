<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Resolver;

use BackedEnum;
use DateTimeImmutable;
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

		$resolvedType = $this->resolveType($type);
		if ($resolvedType === null) {
			return null;
		}

		return FieldContext::named(
			$property->getName(),
			$resolvedType,
			$type->allowsNull(),
		);
	}

	private function resolveType(ReflectionNamedType $type): ?string
	{
		$name = $type->getName();

		if ($type->isBuiltin()) {
			return in_array($name, ['string', 'int', 'bool', 'float'], true)
				? $name
				: null;
		}

		if (enum_exists($name) && is_a($name, BackedEnum::class, true)) {
			return $name;
		}

		if (is_a(DateTimeImmutable::class, $name, true)) {
			return 'datetime';
		}

		return null;
	}

	private function findProperty(MappingNode $node): ?ReflectionProperty
	{
		return $this->propertyFinder->findTargetProperty($node)
			?? $this->propertyFinder->findSourceProperty($node);
	}
}
