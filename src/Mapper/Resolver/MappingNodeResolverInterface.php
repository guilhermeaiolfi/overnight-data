<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Resolver;

use ON\Data\Mapper\MappingNode;

interface MappingNodeResolverInterface
{
	public function resolve(MappingNode $node): ?MappingNode;
}
