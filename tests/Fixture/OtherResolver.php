<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use ON\Data\Mapper\FieldContext;
use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\Resolver\FieldResolverInterface;

final class OtherResolver implements FieldResolverInterface
{
	public function resolve(MappingNode $node): ?FieldContext
	{
		return FieldContext::named((string) $node->getName(), 'string');
	}
}
