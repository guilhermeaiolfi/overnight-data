<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Resolver;

use BackedEnum;
use DateTimeInterface;
use ON\Data\Definition\DefinitionInterface;
use ON\Data\Mapper\Exception\MappingException;
use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\MappingRuntime;
use ON\Data\Mapper\Representation\RepresentationInterface;
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

		if (! $this->mayBeStructuralValue($node->getValue())) {
			return null;
		}

		$inferrer = $this->inferrer
			?? $runtime->getSharedInstance(BranchTargetInferrer::class);

		$reflectionTarget = $inferrer->inferFromReflection($node, $runtime);
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

	private function getDefinition(
		MappingNode $node,
		MappingRuntime $runtime,
	): ?DefinitionInterface {
		if (! $this->discoveryComplete) {
			$this->discoverDefinition($node, $runtime);
		}

		if ($this->ambiguity !== null) {
			throw $this->ambiguity;
		}

		return $this->definition;
	}

	private function discoverDefinition(
		MappingNode $node,
		MappingRuntime $runtime,
	): void {
		$this->discoveryComplete = true;

		try {
			$locator = $this->locator
				?? $runtime->getSharedInstance(
					DefinitionArgumentLocator::class,
				);
			$this->definition = $locator
				->getDefinition($node->getArguments());
		} catch (MappingException $exception) {
			$this->ambiguity = $exception;
		}
	}

	private function mayBeStructuralValue(mixed $value): bool
	{
		if ($value === null) {
			return false;
		}

		if (is_array($value)) {
			return true;
		}

		return is_object($value)
			&& ! $value instanceof DateTimeInterface
			&& ! $value instanceof BackedEnum
			&& ! $value instanceof RepresentationInterface;
	}
}
