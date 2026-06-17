<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Resolver;

use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\Support\MappingNodeTargetResolver;
use ReflectionNamedType;
use stdClass;

final class StructuralValueMappingNodeResolver implements MappingNodeResolverInterface
{
	public function __construct(
		private readonly ?MappingNodeTargetResolver $targetResolver = null,
	) {
	}

	public function resolve(MappingNode $node): ?MappingNode
	{
		$resolver = $this->targetResolver ?? new MappingNodeTargetResolver();
		if (! $resolver->isStructuralValue($node->getValue())) {
			return null;
		}

		$property = $resolver->findTargetProperty($node);
		if ($property !== null) {
			$type = $property->getType();
			if ($type instanceof ReflectionNamedType && $type->isBuiltin()) {
				if ($type->getName() === 'array') {
					return $node->forChild([]);
				}

				if ($type->getName() === 'object') {
					return $node->forChild(stdClass::class);
				}
			}
		}

		$target = $resolver->resolveGenericChildTarget($node);
		if ($target === null) {
			return null;
		}

		return $node->forChild($target);
	}
}
