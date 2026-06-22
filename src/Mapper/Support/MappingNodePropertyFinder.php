<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Support;

use ON\Data\Mapper\Attribute\MapTo;
use ON\Data\Mapper\MappingNode;
use ReflectionClass;
use ReflectionProperty;
use stdClass;

final class MappingNodePropertyFinder
{
	public function findSourceProperty(MappingNode $node): ?ReflectionProperty
	{
		$source = $node->getParentSource();
		$name = $node->getName();

		if (! is_object($source) || ! is_string($name)) {
			return null;
		}

		$reflection = new ReflectionClass($source);
		if (! $reflection->hasProperty($name)) {
			return null;
		}

		return $reflection->getProperty($name);
	}

	public function findTargetProperty(
		MappingNode $node,
		?string $mappedSourceName = null,
	): ?ReflectionProperty {
		$target = $node->getParentTarget();
		if (! is_object($target) || $target instanceof stdClass) {
			return null;
		}

		$matcher = new ObjectPropertyMatcher(new ReflectionClass($target));

		return $matcher->match($mappedSourceName ?? $this->getMappedSourceName($node));
	}

	public function getMappedSourceName(
		MappingNode $node,
		?ReflectionProperty $sourceProperty = null,
	): string {
		$sourceProperty ??= $this->findSourceProperty($node);
		if ($sourceProperty === null) {
			return (string) $node->getName();
		}

		$attributes = $sourceProperty->getAttributes(MapTo::class);
		if ($attributes === []) {
			return $sourceProperty->getName();
		}

		return $attributes[0]->newInstance()->getName();
	}
}
