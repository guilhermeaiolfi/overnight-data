<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Resolver;

use ON\Data\Definition\DefinitionInterface;
use ON\Data\Mapper\Exception\MappingException;
use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\Resolution\BranchNodeResolution;
use ON\Data\Mapper\Resolution\BranchNodeResolutionInterface;
use ON\Data\Mapper\Resolution\LeafNodeResolution;
use ON\Data\Mapper\Resolution\LeafNodeResolutionInterface;
use ON\Data\Mapper\Support\BranchTargetInferrer;
use ON\Data\Mapper\Support\DefinitionArgumentLocator;

final class DefinitionNodeResolver implements NodeResolverInterface
{
	private bool $discoveryComplete = false;

	private ?DefinitionInterface $definition = null;

	private ?MappingException $ambiguity = null;

	public function __construct(
		private readonly ?DefinitionArgumentLocator $locator = null,
		private readonly ?BranchTargetInferrer $inferrer = null,
	) {
	}

	public function resolve(
		MappingNode $node,
	): LeafNodeResolutionInterface|BranchNodeResolutionInterface|null {
		$definition = $this->getDefinition($node);
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

		if (! $this->branchInferrer()->isStructuralValue($node->getValue())) {
			return null;
		}

		$reflectionTarget = $this->branchInferrer()->inferFromReflection($node);
		$genericTarget = $this->branchInferrer()->inferGenericTarget($node->getParentTarget());
		$target = $reflectionTarget['target'] ?? $genericTarget;
		if ($target === null) {
			return null;
		}

		return BranchNodeResolution::named(
			name: $relation->getName(),
			target: $target,
			arguments: $this->branchInferrer()->replaceDefinitionArguments(
				$node->getArguments(),
				$definition,
				$relation->getCollection(),
			),
			collection: $relation->getCardinality() === 'many',
		);
	}

	private function getDefinition(MappingNode $node): ?DefinitionInterface
	{
		if (! $this->discoveryComplete) {
			$this->discoverDefinition($node);
		}

		if ($this->ambiguity !== null) {
			throw $this->ambiguity;
		}

		return $this->definition;
	}

	private function discoverDefinition(MappingNode $node): void
	{
		$this->discoveryComplete = true;

		try {
			$this->definition = ($this->locator ?? new DefinitionArgumentLocator())
				->getDefinition($node->getArguments());
		} catch (MappingException $exception) {
			$this->ambiguity = $exception;
		}
	}

	private function branchInferrer(): BranchTargetInferrer
	{
		return $this->inferrer ?? new BranchTargetInferrer();
	}
}
