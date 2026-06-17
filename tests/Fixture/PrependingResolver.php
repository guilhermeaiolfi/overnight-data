<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use ON\Data\Mapper\FieldContext;
use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\Resolver\FieldResolverInterface;

final class PrependingResolver implements FieldResolverInterface
{
	public function __construct()
	{
		ComponentTestState::recordConstruction(self::class);
	}

	public function resolve(MappingNode $node): ?FieldContext
	{
		ComponentTestState::recordRuntime(self::class, $node->getPath());

		if ($node->getName() !== 'id') {
			return null;
		}

		return FieldContext::named('id', 'string');
	}
}
