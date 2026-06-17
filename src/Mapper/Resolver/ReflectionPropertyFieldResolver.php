<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Resolver;

use ON\Data\Mapper\FieldContext;
use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\Support\MappingNodeTargetResolver;
use ReflectionNamedType;
use ReflectionProperty;

final class ReflectionPropertyFieldResolver implements FieldResolverInterface
{
	public function __construct(
		private readonly ?MappingNodeTargetResolver $targetResolver = null,
	) {
	}

	public function resolve(MappingNode $node): ?FieldContext
	{
		$resolver = $this->targetResolver ?? new MappingNodeTargetResolver();
		$property = $resolver->findTargetProperty($node) ?? $resolver->findSourceProperty($node);
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
