<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Resolver;

use ON\Data\Definition\DefinitionInterface;
use ON\Data\Mapper\Exception\MappingException;
use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\Support\MappingNodeTargetResolver;

final class DefinitionRelationMappingNodeResolver implements MappingNodeResolverInterface
{
	private bool $discoveryComplete = false;

	private ?DefinitionInterface $definition = null;

	private ?MappingException $ambiguity = null;

	public function __construct(
		private readonly ?MappingNodeTargetResolver $targetResolver = null,
	) {
	}

	public function resolve(MappingNode $node): ?MappingNode
	{
		$definition = $this->getDefinition($node);
		if ($definition === null || ! is_string($node->getName())) {
			return null;
		}

		$relation = $definition->getRelation($node->getName());
		if ($relation === null) {
			return null;
		}

		$targetResolver = $this->targetResolver ?? new MappingNodeTargetResolver();
		$target = $targetResolver->resolveGenericChildTarget($node);
		$reflectionNode = (new ReflectionMappingNodeResolver($targetResolver))->resolve($node);
		if ($reflectionNode !== null) {
			$target = $reflectionNode->getChildTarget();
		}

		if ($target === null) {
			return null;
		}

		return $node->forChild(
			target: $target,
			collection: $relation->getCardinality() === 'many',
			arguments: $this->replaceDefinition(
				$node->getContext()->getArguments(),
				$definition,
				$relation->getCollection(),
			),
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

		$definitions = [];
		foreach ($node->getContext()->getArguments() as $argument) {
			if ($argument instanceof DefinitionInterface) {
				$definitions[] = $argument;
			}
		}

		if ($definitions === []) {
			return;
		}

		if (count($definitions) === 1) {
			$this->definition = $definitions[0];

			return;
		}

		$names = array_map(
			static fn (DefinitionInterface $definition): string => sprintf('"%s"', $definition->getName()),
			$definitions,
		);

		$this->ambiguity = new MappingException(
			sprintf(
				'Definition field resolution is ambiguous: mapping arguments contain %d definitions %s.',
				count($definitions),
				implode(' and ', $names),
			),
		);
	}

	/**
	 * @param list<mixed> $arguments
	 *
	 * @return list<mixed>
	 */
	private function replaceDefinition(
		array $arguments,
		DefinitionInterface $current,
		DefinitionInterface $replacement,
	): array {
		$replaced = false;
		$result = [];

		foreach ($arguments as $argument) {
			if ($argument instanceof DefinitionInterface) {
				if ($argument === $current && ! $replaced) {
					$result[] = $replacement;
					$replaced = true;
				}

				continue;
			}

			$result[] = $argument;
		}

		if (! $replaced) {
			$result[] = $replacement;
		}

		return $result;
	}
}
