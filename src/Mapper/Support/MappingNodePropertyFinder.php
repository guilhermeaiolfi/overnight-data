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
		return $this->extractReflectionProperty($node->getArguments());
	}

	public function findTargetProperty(MappingNode $node): ?ReflectionProperty
	{
		$target = $node->getContext()->getTarget();
		if (! is_object($target) || $target instanceof stdClass) {
			return null;
		}

		$matcher = new ObjectPropertyMatcher(new ReflectionClass($target));

		return $matcher->match($node->getName());
	}

	private function extractReflectionProperty(mixed $argument): ?ReflectionProperty
	{
		if ($argument instanceof ReflectionProperty) {
			return $argument;
		}

		if (! is_array($argument)) {
			return null;
		}

		foreach ($argument as $value) {
			$property = $this->extractReflectionProperty($value);
			if ($property instanceof ReflectionProperty) {
				return $property;
			}
		}

		return null;
	}
}
