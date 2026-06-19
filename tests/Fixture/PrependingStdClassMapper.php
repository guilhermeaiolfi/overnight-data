<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use ON\Data\Mapper\Mapper\Mapper;
use ON\Data\Mapper\MapperManager;
use ON\Data\Mapper\MappingContext;
use ON\Data\Mapper\MappingNode;
use stdClass;

final class PrependingStdClassMapper extends Mapper
{
	public function __construct()
	{
		ComponentTestState::recordConstruction(self::class);
	}

	public static function canMap(
		mixed $source,
		MappingContext $context,
	): bool {
		ComponentTestState::recordSelection(self::class);

		return $source instanceof stdClass;
	}

	public function map(
		MappingNode $node,
		MapperManager $mapperManager,
	): mixed {
		ComponentTestState::recordRuntime(self::class, $node->getPath());

		if ($node->isCollection()) {
			return $this->mapCollection($node, $mapperManager);
		}

		$writer = $mapperManager->resolveWriter($node->getTarget(), $node->getContext());
		$result = $writer->prepare($node->getTarget(), $node->getContext());
		$frame = $node->withTarget($result);
		$resolvers = $mapperManager->createResolverChain($frame->getContext());
		$converter = $mapperManager->createFieldConversionCoordinator();
		$child = $frame->child('specialized', 'Mapper');
		$mappedValue = $this->mapChild($child, $resolvers, $converter, $mapperManager);
		$result = $writer->write($result, $child, $mappedValue);

		return $writer->finish($result, $frame->getContext());
	}
}
