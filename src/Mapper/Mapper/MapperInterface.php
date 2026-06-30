<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Mapper;

use ON\Data\Mapper\MappingBranch;
use ON\Data\Mapper\MappingOptions;

interface MapperInterface
{
	public static function canMap(
		mixed $source,
		MappingOptions $options,
	): bool;

	public function map(MappingBranch $context): mixed;
}
