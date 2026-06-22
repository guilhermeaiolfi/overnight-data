<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use ON\Data\Mapper\Mapper\MapperInterface;
use ON\Data\Mapper\MappingContext;
use ON\Data\Mapper\MappingRuntime;

final class NeverMapper implements MapperInterface
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

	public function map(MappingRuntime $runtime): mixed
	{
		ComponentTestState::recordRuntime(self::class, $runtime->getMappingNode()->getPath());

		return [];
	}
}
