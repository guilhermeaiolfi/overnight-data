<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use ON\Data\Mapper\Mapper\MapperInterface;
use ON\Data\Mapper\MappingContext;
use ON\Data\Mapper\MappingOptions;

final class NeverMapper implements MapperInterface
{
	public function __construct()
	{
		ComponentTestState::recordConstruction(self::class);
	}

	public static function canMap(
		mixed $source,
		MappingOptions $options,
	): bool {
		ComponentTestState::recordSelection(self::class);

		return false;
	}

	public function map(MappingContext $context): mixed
	{
		ComponentTestState::recordRuntime(self::class, $context->getNode()->getPath());

		return [];
	}
}
