<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use ON\Data\Mapper\Mapper\Mapper;
use ON\Data\Mapper\MapperManager;
use ON\Data\Mapper\MappingContext;
use ON\Data\Mapper\MappingNode;

final class NeverMapper extends Mapper
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

		return false;
	}

	public function map(
		MappingNode $node,
		MapperManager $mapperManager,
	): mixed {
		ComponentTestState::recordRuntime(self::class, $node->getPath());

		return [];
	}
}
