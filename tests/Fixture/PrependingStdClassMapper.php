<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use ON\Data\Mapper\Mapper\Mapper;
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

	protected function getNodes(
		MappingNode $node,
	): iterable {
		ComponentTestState::recordRuntime(self::class, $node->getPath());

		yield $node->child('specialized', 'Mapper');
	}
}
