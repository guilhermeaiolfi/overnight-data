<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Walker;

use ON\Data\Mapper\Exception\MappingException;
use ON\Data\Mapper\MapperManager;
use ON\Data\Mapper\MappingContext;
use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\Resolver\MappingNodeResolverInterface;
use stdClass;

abstract class Walker implements WalkerInterface
{
	final public function walk(
		mixed $source,
		mixed $target,
		MappingContext $context,
		MapperManager $mappers,
	): mixed {
		if ($context->isCollection()) {
			if (! is_iterable($source)) {
				throw new MappingException('Collection mapping requires an iterable source.');
			}

			$results = [];
			foreach ($source as $key => $item) {
				if (
					is_string($target)
					&& $target !== stdClass::class
					&& is_object($item)
					&& $item instanceof $target
				) {
					$results[] = $item;

					continue;
				}

				$results[] = $mappers->map(
					$item,
					$target,
					$context
						->forChild($item, $context->getArguments(), false, true)
						->withPathSegment((string) $key),
				);
			}

			return $results;
		}

		$writer = $mappers->resolveWriter($target, $context);
		$result = $writer->prepare($target, $context);
		$levelContext = $context->enter($source, $result);
		$fieldCoordinator = $mappers->createFieldConversionCoordinator($levelContext);
		$nodeResolvers = $mappers->createMappingNodeResolverCoordinator($levelContext);

		foreach ($this->getNodes($source, $levelContext) as $node) {
			$node = $node->withContext($levelContext->withPathSegment((string) $node->getName()));
			$node = $this->resolveNode($node, $nodeResolvers);

			if ($node->hasChildMapping() && $node->getValue() !== null) {
				$value = $mappers->map(
					$node->getValue(),
					$node->getChildTarget(),
					$levelContext->forChild(
						$node->getValue(),
						$node->getChildArguments(),
						$node->isChildCollection(),
					)->withPath($node->getContext()->getPath()),
				);
			} else {
				$field = $fieldCoordinator->resolveField($node);
				$value = $fieldCoordinator->convertScalar($node->getValue(), $field, $node->getContext());
			}

			$result = $writer->write($result, $node, $value);
			$levelContext = $levelContext->enter($source, $result);
		}

		return $writer->finish($result, $levelContext);
	}

	/**
	 * @return iterable<MappingNode>
	 */
	abstract protected function getNodes(
		mixed $source,
		MappingContext $context,
	): iterable;

	/**
	 * @param list<MappingNodeResolverInterface> $resolvers
	 */
	private function resolveNode(MappingNode $node, array $resolvers): MappingNode
	{
		foreach ($resolvers as $resolver) {
			$resolved = $resolver->resolve($node);
			if ($resolved !== null) {
				return $resolved;
			}
		}

		return $node;
	}
}
