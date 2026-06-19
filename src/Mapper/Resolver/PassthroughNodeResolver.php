<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Resolver;

use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\Resolution\LeafNodeResolution;
use ON\Data\Mapper\Resolution\LeafNodeResolutionInterface;

final class PassthroughNodeResolver implements NodeResolverInterface
{
	public function resolve(MappingNode $node): LeafNodeResolutionInterface
	{
		return LeafNodeResolution::passthrough((string) $node->getName());
	}
}
