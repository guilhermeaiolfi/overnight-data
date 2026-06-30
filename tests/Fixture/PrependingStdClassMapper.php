<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use ON\Data\Mapper\Mapper\MapperInterface;
use ON\Data\Mapper\MappingBranch;
use ON\Data\Mapper\MappingOptions;
use stdClass;

final class PrependingStdClassMapper implements MapperInterface
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

		return $source instanceof stdClass;
	}

	public function map(MappingBranch $context): mixed
	{
		ComponentTestState::recordRuntime(self::class, $context->getNode()->getPath());
		$context->write(name: 'specialized', value: 'Mapper');

		return $context->getResult();
	}
}
