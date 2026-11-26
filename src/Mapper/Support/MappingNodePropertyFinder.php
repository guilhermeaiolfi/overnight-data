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
	/** @var array<class-string, ObjectPropertyMatcher> */
	private array $targetMatchers = [];

	/** @var array<class-string, array<string, ReflectionProperty|null>> */
	private array $sourceProperties = [];

	/** @var array<class-string, array<string, string>> */
	private array $mappedSourceNames = [];

	public function findSourceProperty(MappingNode $node): ?ReflectionProperty
	{
		$source = $node->getParentSource();
		$name = $node->getName();

		if (! is_object($source) || ! is_string($name)) {
			return null;
		}

		$class = $source::class;
		if (array_key_exists($name, $this->sourceProperties[$class] ?? [])) {
			return $this->sourceProperties[$class][$name];
		}

		$reflection = new ReflectionClass($class);
		if (! $reflection->hasProperty($name)) {
			$this->sourceProperties[$class][$name] = null;

			return null;
		}

		return $this->sourceProperties[$class][$name] = $reflection->getProperty($name);
	}

	public function findTargetProperty(
		MappingNode $node,
		?string $mappedSourceName = null,
	): ?ReflectionProperty {
		return $this->targetMatcherFor($node)?->match(
			$mappedSourceName ?? $this->getMappedSourceName($node),
		);
	}

	/** @return array{ReflectionProperty|null, ReflectionProperty|null} */
	public function findSourceAndTargetProperties(MappingNode $node): array
	{
		$sourceProperty = $this->findSourceProperty($node);
		$targetProperty = $this->targetMatcherFor($node)?->match(
			$this->mappedSourceName($node, $sourceProperty),
		);

		return [$sourceProperty, $targetProperty];
	}

	public function getMappedSourceName(
		MappingNode $node,
		?ReflectionProperty $sourceProperty = null,
	): string {
		return $this->mappedSourceName($node, $sourceProperty ?? $this->findSourceProperty($node));
	}

	private function targetMatcherFor(MappingNode $node): ?ObjectPropertyMatcher
	{
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

		return $this->targetMatchers[$class]
			??= new ObjectPropertyMatcher(new ReflectionClass($class));
	}

	private function mappedSourceName(
		MappingNode $node,
		?ReflectionProperty $sourceProperty,
	): string {
		if ($sourceProperty === null) {
			return (string) $node->getName();
		}

		$source = $node->getParentSource();
		$propertyName = $sourceProperty->getName();
		if (is_object($source)) {
			$class = $source::class;
			if (isset($this->mappedSourceNames[$class][$propertyName])) {
				return $this->mappedSourceNames[$class][$propertyName];
			}
		}

		$attributes = $sourceProperty->getAttributes(MapTo::class);
		if ($attributes === []) {
			$mappedSourceName = $propertyName;
		} else {
			$mappedSourceName = $attributes[0]->newInstance()->getName();
		}

		if (is_object($source)) {
			$this->mappedSourceNames[$source::class][$propertyName] = $mappedSourceName;
		}

		return $mappedSourceName;
	}
}
