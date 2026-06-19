<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Mapper;

use ON\Data\Mapper\Exception\MappingException;
use ON\Data\Mapper\FieldConversionCoordinator;
use ON\Data\Mapper\MapperManager;
use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\Resolution\BranchNodeResolutionInterface;
use ON\Data\Mapper\Resolution\LeafNodeResolution;
use ON\Data\Mapper\Resolution\LeafNodeResolutionInterface;
use ON\Data\Mapper\Resolver\NodeResolverInterface;
use stdClass;

abstract class Mapper implements MapperInterface
{
	final protected function mapCollection(MappingNode $node, MapperManager $mapperManager): array
	{
		if (! is_iterable($node->getValue())) {
			throw new MappingException('Collection mapping requires an iterable source.');
		}

		$results = [];
		foreach ($node->getValue() as $key => $item) {
			$targetClass = is_string($node->getTarget()) ? $node->getTarget() : null;

			if (
				$targetClass !== null
				&& $targetClass !== stdClass::class
				&& is_object($item)
				&& $item instanceof $targetClass
			) {
				$results[] = $item;

				continue;
			}

			$results[] = $mapperManager->mapNode(
				$node
					->child((string) $key, $item)
					->forMapping($node->getTarget(), $node->getArguments(), false, true),
			);
		}

		return $results;
	}

	/**
	 * @param list<NodeResolverInterface> $resolvers
	 */
	final protected function mapChild(
		MappingNode $child,
		array $resolvers,
		FieldConversionCoordinator $converter,
		MapperManager $mapperManager,
	): mixed {
		$resolution = $this->resolveNode($child, $resolvers);

		if ($resolution instanceof BranchNodeResolutionInterface) {
			if ($child->getValue() === null) {
				return null;
			}

			return $mapperManager->mapNode(
				$child->forMapping(
					$resolution->getTarget(),
					$resolution->getArguments(),
					$resolution->isCollection(),
				),
			);
		}

		return $converter->convert($child->getValue(), $resolution, $child);
	}

	/**
	 * @param list<NodeResolverInterface> $resolvers
	 */
	final protected function resolveNode(MappingNode $node, array $resolvers): LeafNodeResolutionInterface|BranchNodeResolutionInterface
	{
		foreach ($resolvers as $resolver) {
			$resolution = $resolver->resolve($node);
			if ($resolution !== null) {
				return $resolution;
			}
		}

		return LeafNodeResolution::passthrough((string) $node->getName());
	}
}
