<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Resolver;

use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\MappingRuntime;
use ON\Data\Mapper\Resolution\ResolutionNodeInterface;

interface CacheableNodeResolverInterface extends NodeResolverInterface
{
	public function isResolutionCacheable(
		MappingNode $node,
		?ResolutionNodeInterface $resolution,
		MappingRuntime $runtime,
	): bool;
}
