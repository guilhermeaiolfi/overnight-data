<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\MappingRuntime;
use ON\Data\Mapper\Resolution\LeafNodeResolution;
use ON\Data\Mapper\Resolver\NodeResolverInterface;

final class OtherResolver implements NodeResolverInterface
{
	public function resolve(
		MappingNode $node,
		MappingRuntime $runtime,
	): ?LeafNodeResolution {
		return LeafNodeResolution::named((string) $node->getName(), 'string');
	}
}
