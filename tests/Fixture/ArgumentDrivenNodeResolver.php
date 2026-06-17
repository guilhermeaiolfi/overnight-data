<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\Resolver\MappingNodeResolverInterface;

final class ArgumentDrivenNodeResolver implements MappingNodeResolverInterface
{
	public function resolve(MappingNode $node): ?MappingNode
	{
		foreach ($node->getContext()->getArguments() as $argument) {
			if (
				$argument instanceof CustomNodeResolverArgument
				&& $argument->fieldName === $node->getName()
			) {
				return $node->forChild($argument->target, $argument->collection, $node->getContext()->getArguments());
			}
		}

		return null;
	}
}
