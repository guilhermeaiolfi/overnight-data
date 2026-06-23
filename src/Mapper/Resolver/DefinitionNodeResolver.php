<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Resolver;

use ON\Data\Definition\DefinitionInterface;
use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\MappingRuntime;
use ON\Data\Mapper\Resolution\BranchNodeResolution;
use ON\Data\Mapper\Resolution\BranchNodeResolutionInterface;
use ON\Data\Mapper\Resolution\LeafNodeResolution;
use ON\Data\Mapper\Resolution\LeafNodeResolutionInterface;
use ON\Data\Mapper\Resolution\ResolutionNodeInterface;
use ON\Data\Mapper\Support\BranchTargetInferrer;
use ON\Data\Mapper\Support\DefinitionArgumentLocator;
use ON\Data\Mapper\Support\MappingNodePropertyFinder;

final class DefinitionNodeResolver implements CacheableNodeResolverInterface
{
	private ?DefinitionArgumentLocator $runtimeLocator = null;

	private ?BranchTargetInferrer $runtimeInferrer = null;

	private ?MappingNodePropertyFinder $runtimePropertyFinder = null;

	public function __construct(
		private readonly ?DefinitionArgumentLocator $locator = null,
		private readonly ?BranchTargetInferrer $inferrer = null,
	) {
	}

	public function resolve(
		MappingNode $node,
		MappingRuntime $runtime,
	): LeafNodeResolutionInterface|BranchNodeResolutionInterface|null {
		$definition = $this->getDefinition($node, $runtime);
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

		$inferrer = $this->getInferrer($runtime);

		$reflectionTarget = $inferrer->inferFromReflection(
			$node,
			$this->getPropertyFinder($runtime),
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
			collection: $relation->getCardinality() === 'many',
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

		$definition = $this->getDefinition($node, $runtime);
		if ($definition === null || ! is_string($node->getName())) {
			return true;
		}

		if ($definition->getField($node->getName()) !== null) {
			return true;
		}

		return $definition->getRelation($node->getName()) === null;
	}

	private function getDefinition(
		MappingNode $node,
		MappingRuntime $runtime,
	): ?DefinitionInterface {
		return $this->getLocator($runtime)
			->getDefinition($node->getArguments());
	}

	private function getLocator(
		MappingRuntime $runtime,
	): DefinitionArgumentLocator {
		return $this->locator
			?? (
				$this->runtimeLocator
				??= $runtime->getSharedInstance(
					DefinitionArgumentLocator::class,
				)
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

	private function getPropertyFinder(
		MappingRuntime $runtime,
	): MappingNodePropertyFinder {
		return $this->runtimePropertyFinder
			??= $runtime->getSharedInstance(
				MappingNodePropertyFinder::class,
			);
	}
}
