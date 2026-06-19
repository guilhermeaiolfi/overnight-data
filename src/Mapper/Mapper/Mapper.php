<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Mapper;

use ON\Data\Mapper\Exception\MappingException;
use ON\Data\Mapper\MapperManager;
use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\Resolution\BranchNodeResolutionInterface;
use ON\Data\Mapper\Resolution\LeafNodeResolution;
use ON\Data\Mapper\Resolution\LeafNodeResolutionInterface;
use ON\Data\Mapper\Resolver\NodeResolverInterface;
use stdClass;

abstract class Mapper implements MapperInterface
{
	final public function map(
		MappingNode $node,
		MapperManager $mapperManager,
	): mixed {
		if ($node->isCollection()) {
			return $this->mapCollection($node, $mapperManager);
		}

		$writer = $mapperManager->resolveWriter($node->getTarget(), $node->getContext());
		$result = $writer->prepare($node->getTarget(), $node->getContext());
		$frame = $node->withTarget($result);
		$resolvers = $mapperManager->createResolverChain($frame->getContext());
		$converter = $mapperManager->createFieldConversionCoordinator();

		foreach ($this->getNodes($frame) as $child) {
			$resolution = $this->resolveNode($child, $resolvers);

			if ($resolution instanceof BranchNodeResolutionInterface) {
				$value = $child->getValue() === null
					? null
					: $mapperManager->mapNode(
						$child->forMapping(
							$resolution->getTarget(),
							$resolution->getArguments(),
							$resolution->isCollection(),
						),
					);
			} else {
				$value = $converter->convert($child->getValue(), $resolution, $child);
			}

			$result = $writer->write($result, $child, $value);
		}

		return $writer->finish($result, $frame->getContext());
	}

	/**
	 * @return iterable<MappingNode>
	 */
	abstract protected function getNodes(MappingNode $node): iterable;

	private function mapCollection(MappingNode $node, MapperManager $mapperManager): array
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
	private function resolveNode(MappingNode $node, array $resolvers): LeafNodeResolutionInterface|BranchNodeResolutionInterface
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
