<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use ON\Data\Mapper\Mapper\MapperInterface;
use ON\Data\Mapper\MappingContext;
use ON\Data\Mapper\MappingRuntime;
use stdClass;

final class PrependingStdClassMapper implements MapperInterface
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

	public function map(MappingRuntime $runtime): mixed
	{
		ComponentTestState::recordRuntime(self::class, $runtime->getMappingNode()->getPath());
		$runtime->write(name: 'specialized', value: 'Mapper');

		return $runtime->getResult();
	}
}
