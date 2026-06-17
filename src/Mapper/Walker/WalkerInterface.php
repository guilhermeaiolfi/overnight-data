<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Walker;

use ON\Data\Mapper\MapperManager;
use ON\Data\Mapper\MappingContext;
use ON\Data\Mapper\MappingNode;

interface WalkerInterface
{
	public static function canWalk(
		mixed $source,
		MappingContext $context,
	): bool;

	public function walk(MappingNode $node, MapperManager $mappers): mixed;
}
