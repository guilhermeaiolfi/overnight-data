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
	public function __construct(private readonly ReflectionMetadataCache $metadata = new ReflectionMetadataCache())
	{
	}

	public function findSourceProperty(MappingNode $node): ?ReflectionProperty
	{
		$source = $node->getParentSource();
		$name = $node->getName();

		if (! is_object($source) || $source::class === stdClass::class || ! is_string($name)) {
			return null;
		}

		$class = $source::class;
		$metadata = $this->metadata->forClass($class);
		if (isset($metadata->sourceProperties[$name])) {
			return $metadata->sourceProperties[$name];
		}

		$reflection = $metadata->reflection ??= new ReflectionClass($class);
		if (! $reflection->hasProperty($name)) {
			return null;
		}

		return $metadata->sourceProperties[$name] = $reflection->getProperty($name);
	}

	public function findTargetProperty(
		MappingNode $node,
		?string $mappedSourceName = null,
	): ?ReflectionProperty {
		$target = $node->getParentTarget();
		if ($target instanceof stdClass || $target === stdClass::class) {
			return null;
		}

		$class = is_object($target)
			? $target::class
			: (is_string($target) ? $target : null);
		if (! is_string($class) || ! class_exists($class)) {
			return null;
		}

		return $this->matcherFor($class)->match(
			$mappedSourceName ?? $this->getMappedSourceName($node),
		);
	}

	public function getMappedSourceName(
		MappingNode $node,
		?ReflectionProperty $sourceProperty = null,
	): string {
		$sourceProperty ??= $this->findSourceProperty($node);
		if ($sourceProperty === null) {
			return (string) $node->getName();
		}

		$source = $node->getParentSource();
		$propertyName = $sourceProperty->getName();
		$metadata = is_object($source) ? $this->metadata->forClass($source::class) : null;
		if (isset($metadata->mappedSourceNames[$propertyName])) {
			return $metadata->mappedSourceNames[$propertyName];
		}

		$attributes = $sourceProperty->getAttributes(MapTo::class);
		if ($attributes === []) {
			$mappedSourceName = $propertyName;
		} else {
			$mappedSourceName = $attributes[0]->newInstance()->getName();
		}

		if ($metadata !== null) {
			$metadata->mappedSourceNames[$propertyName] = $mappedSourceName;
		}

		return $mappedSourceName;
	}

	/**
	 * @param class-string $class
	 */
	private function matcherFor(string $class): ObjectPropertyMatcher
	{
		$metadata = $this->metadata->forClass($class);

		return $metadata->targetMatcher ??= new ObjectPropertyMatcher($metadata->reflection ??= new ReflectionClass($class));
	}
}
