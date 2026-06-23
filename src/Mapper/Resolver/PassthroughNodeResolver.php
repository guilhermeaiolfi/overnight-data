<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Resolver;

use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\MappingRuntime;
use ON\Data\Mapper\Resolution\LeafNodeResolution;
use ON\Data\Mapper\Resolution\LeafNodeResolutionInterface;
use ON\Data\Mapper\Resolution\ResolutionNodeInterface;

final class PassthroughNodeResolver implements CacheableNodeResolverInterface
{
	public function resolve(
		MappingNode $node,
		MappingRuntime $runtime,
	): LeafNodeResolutionInterface {
		return LeafNodeResolution::passthrough((string) $node->getName());
	}

	public function isResolutionCacheable(
		MappingNode $node,
		?ResolutionNodeInterface $resolution,
		MappingRuntime $runtime,
	): bool {
		return $resolution instanceof LeafNodeResolutionInterface;
	}
}
