<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Resolver;

use BackedEnum;
use DateTimeImmutable;
use DateTimeInterface;
use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\Resolution\BranchNodeResolution;
use ON\Data\Mapper\Resolution\BranchNodeResolutionInterface;
use ON\Data\Mapper\Resolution\LeafNodeResolution;
use ON\Data\Mapper\Resolution\LeafNodeResolutionInterface;
use ON\Data\Mapper\Support\BranchTargetInferrer;
use ON\Data\Mapper\Support\MappingNodePropertyFinder;
use ReflectionNamedType;
use ReflectionProperty;

final class ReflectionPropertyNodeResolver implements NodeResolverInterface
{
	private readonly MappingNodePropertyFinder $propertyFinder;

	public function __construct(
		private readonly ?BranchTargetInferrer $inferrer = null,
	) {
		$this->propertyFinder = new MappingNodePropertyFinder();
	}

	public function resolve(
		MappingNode $node,
	): LeafNodeResolutionInterface|BranchNodeResolutionInterface|null {
		$property = $this->findProperty($node);
		if (! $property instanceof ReflectionProperty) {
			return null;
		}

		$type = $property->getType();
		if ($type instanceof ReflectionNamedType) {
			$resolvedType = $this->resolveLeafType($type);
			if ($resolvedType !== null) {
				return LeafNodeResolution::named(
					$property->getName(),
					$resolvedType,
					$type->allowsNull(),
				);
			}
		}

		if (! $this->branchInferrer()->isStructuralValue($node->getValue())) {
			return null;
		}

		$target = $this->branchInferrer()->inferFromReflection($node);
		if ($target === null) {
			return null;
		}

		return BranchNodeResolution::make(
			$target['target'],
			$target['arguments'],
			$target['collection'],
		);
	}

	private function resolveLeafType(ReflectionNamedType $type): ?string
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

		if ($name === DateTimeInterface::class || is_a($name, DateTimeImmutable::class, true)) {
			return 'datetime';
		}

		return null;
	}

	private function findProperty(MappingNode $node): ?ReflectionProperty
	{
		return $this->propertyFinder->findTargetProperty($node)
			?? $this->propertyFinder->findSourceProperty($node);
	}

	private function branchInferrer(): BranchTargetInferrer
	{
		return $this->inferrer ?? new BranchTargetInferrer();
	}
}
