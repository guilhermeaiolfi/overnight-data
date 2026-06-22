<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Mapper;

use ON\Data\Mapper\MappingContext;
use ON\Data\Mapper\MappingRuntime;

interface MapperInterface
{
	public static function canMap(
		mixed $source,
		MappingContext $context,
	): bool;

	public function map(MappingRuntime $runtime): mixed;
}
