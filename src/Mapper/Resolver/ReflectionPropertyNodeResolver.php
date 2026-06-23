<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Resolver;

use BackedEnum;
use DateTimeImmutable;
use DateTimeInterface;
use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\MappingRuntime;
use ON\Data\Mapper\Resolution\BranchNodeResolution;
use ON\Data\Mapper\Resolution\BranchNodeResolutionInterface;
use ON\Data\Mapper\Resolution\LeafNodeResolution;
use ON\Data\Mapper\Resolution\LeafNodeResolutionInterface;
use ON\Data\Mapper\Support\BranchTargetInferrer;
use ON\Data\Mapper\Support\MappingNodePropertyFinder;
use ReflectionNamedType;
use ReflectionProperty;
use stdClass;

final class ReflectionPropertyNodeResolver implements NodeResolverInterface
{
	private ?MappingNodePropertyFinder $runtimePropertyFinder = null;

	private ?BranchTargetInferrer $runtimeInferrer = null;

	public function __construct(
		private readonly ?BranchTargetInferrer $inferrer = null,
	) {
	}

	public function resolve(
		MappingNode $node,
		MappingRuntime $runtime,
	): LeafNodeResolutionInterface|BranchNodeResolutionInterface|null {
		if (! $this->mayHaveReflectionMetadata($node)) {
			return null;
		}

		$propertyFinder = $this->getPropertyFinder($runtime);
		$sourceProperty = $propertyFinder->findSourceProperty($node);
		$mappedSourceName = $propertyFinder->getMappedSourceName($node, $sourceProperty);
		$targetProperty = $propertyFinder->findTargetProperty($node, $mappedSourceName);
		$property = $targetProperty ?? $sourceProperty;
		if (! $property instanceof ReflectionProperty) {
			return null;
		}

		$name = $targetProperty !== null
			? $targetProperty->getName()
			: $mappedSourceName;
		$type = $property->getType();
		if ($type instanceof ReflectionNamedType) {
			$resolvedType = $this->resolveLeafType($type);
			if ($resolvedType !== null) {
				return LeafNodeResolution::named(
					$name,
					$resolvedType,
					$type->allowsNull(),
				);
			}
		}

		if (! BranchTargetInferrer::isStructuralValue($node->getValue())) {
			return null;
		}

		$inferrer = $this->getInferrer($runtime);

		$target = $inferrer->inferFromReflection($node, $propertyFinder);
		if ($target === null) {
			return null;
		}

		return BranchNodeResolution::named(
			name: $name,
			target: $target['target'],
			arguments: $target['arguments'],
			collection: $target['collection'],
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

	private function mayHaveReflectionMetadata(
		MappingNode $node,
	): bool {
		$source = $node->getParentSource();
		if (is_object($source) && ! $source instanceof stdClass) {
			return true;
		}

		$target = $node->getParentTarget();

		return is_object($target)
			&& ! $target instanceof stdClass;
	}

	private function getPropertyFinder(
		MappingRuntime $runtime,
	): MappingNodePropertyFinder {
		return $this->runtimePropertyFinder
			??= $runtime->getSharedInstance(
				MappingNodePropertyFinder::class,
			);
	}

	private function getInferrer(
		MappingRuntime $runtime,
	): BranchTargetInferrer {
		return $this->inferrer
			?? (
				$this->runtimeInferrer
				??= $runtime->getSharedInstance(
					BranchTargetInferrer::class,
				)
			);
	}
}
