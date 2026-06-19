<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use ON\Data\Mapper\Mapper\Mapper;
use ON\Data\Mapper\MapperManager;
use ON\Data\Mapper\MappingContext;
use ON\Data\Mapper\MappingNode;

final class OtherArrayMapper extends Mapper
{
	public static function canMap(
		mixed $source,
		MappingContext $context,
	): bool {
		return is_array($source);
	}

	public function map(
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

		foreach ($node->getValue() as $name => $value) {
			$child = $frame->child($name, $value);
			$mappedValue = $this->mapChild($child, $resolvers, $converter, $mapperManager);
			$result = $writer->write($result, $child, $mappedValue);
		}

		return $writer->finish($result, $frame->getContext());
	}
}
