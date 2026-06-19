<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Mapper;

use ON\Data\Mapper\MapperManager;
use ON\Data\Mapper\MappingContext;
use ON\Data\Mapper\MappingNode;

interface MapperInterface
{
	public static function canMap(
		mixed $source,
		MappingContext $context,
	): bool;

	public function map(MappingNode $node, MapperManager $mapperManager): mixed;
}
