<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Resolver;

use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\MappingRuntime;
use ON\Data\Mapper\Resolution\BranchNodeResolution;
use ON\Data\Mapper\Resolution\BranchNodeResolutionInterface;
use ON\Data\Mapper\Resolution\LeafNodeResolution;
use ON\Data\Mapper\Resolution\LeafNodeResolutionInterface;
use ON\Data\Mapper\Resolution\ResolutionNodeInterface;
use ON\Data\Mapper\Support\BranchTargetInferrer;
use ON\Data\Mapper\Support\MappingArguments;
use ON\Data\Mapper\Support\MappingNodePropertyFinder;

final class DefinitionNodeResolver implements CacheableNodeResolverInterface
{
	public function resolve(
		MappingNode $node,
		MappingRuntime $runtime,
	): LeafNodeResolutionInterface|BranchNodeResolutionInterface|null {
		$definition = MappingArguments::definition($node->getArguments());
		if ($definition === null || ! is_string($node->getName())) {
			return null;
		}

		$field = $definition->getField($node->getName());
		if ($field !== null) {
			return LeafNodeResolution::fromField($field);
		}

		$relation = $definition->getRelation($node->getName());
		if ($relation === null) {
			return null;
		}

		if (! BranchTargetInferrer::isStructuralValue($node->getValue())) {
			return null;
		}

		$inferrer = $runtime->getSharedInstance(BranchTargetInferrer::class);

		$finder = $runtime->getSharedInstance(MappingNodePropertyFinder::class);
		$reflectionTarget = $inferrer->inferFromReflection(
			$node,
			$finder,
		);
		$genericTarget = $inferrer->inferGenericTarget($node->getParentTarget());
		$target = $reflectionTarget['target'] ?? $genericTarget;
		if ($target === null) {
			return null;
		}

		return BranchNodeResolution::named(
			name: $relation->getName(),
			target: $target,
			arguments: $inferrer->replaceDefinitionArguments(
				$node->getArguments(),
				$definition,
				$relation->getCollection(),
			),
			collection: $relation->getCardinality()->isMany(),
		);
	}

	public function isResolutionCacheable(
		MappingNode $node,
		?ResolutionNodeInterface $resolution,
		MappingRuntime $runtime,
	): bool {
		if ($resolution instanceof LeafNodeResolutionInterface) {
			return true;
		}

		if ($resolution instanceof BranchNodeResolutionInterface) {
			return false;
		}

		$definition = MappingArguments::definition($node->getArguments());
		if ($definition === null || ! is_string($node->getName())) {
			return true;
		}

		if ($definition->getField($node->getName()) !== null) {
			return true;
		}

		return $definition->getRelation($node->getName()) === null;
	}
}
