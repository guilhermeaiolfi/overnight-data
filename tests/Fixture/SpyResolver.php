<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\Resolution\LeafNodeResolution;
use ON\Data\Mapper\Resolver\NodeResolverInterface;

final class SpyResolver implements NodeResolverInterface
{
	public function __construct()
	{
		ComponentTestState::recordConstruction(self::class);
	}

	public function resolve(MappingNode $node): ?LeafNodeResolution
	{
		ComponentTestState::recordRuntime(self::class, $node->getPath());

		if ($node->getName() !== 'id') {
			return null;
		}

		return LeafNodeResolution::named('id', 'int');
	}
}
