<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Resolver;

use ON\Data\Mapper\FieldContext;
use ON\Data\Mapper\MappingNode;

interface FieldResolverInterface
{
	public function resolve(MappingNode $node): ?FieldContext;
}
