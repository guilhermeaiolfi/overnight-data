<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Support;

use ON\Data\Mapper\MappingNode;
use ReflectionClass;
use ReflectionProperty;
use stdClass;

final class MappingNodePropertyFinder
{
	public function findSourceProperty(MappingNode $node): ?ReflectionProperty
	{
		return $node->getSourceProperty();
	}

	public function findTargetProperty(MappingNode $node): ?ReflectionProperty
	{
		$target = $node->getParentTarget();
		if (! is_object($target) || $target instanceof stdClass) {
			return null;
		}

		$matcher = new ObjectPropertyMatcher(new ReflectionClass($target));

		return $matcher->match($node->getName());
	}
}
