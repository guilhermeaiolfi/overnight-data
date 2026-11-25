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
	private static array $targetMatchers = [];

	/** @var array<class-string, array<string, ReflectionProperty>> */
	private static array $sourceProperties = [];

	/** @var array<class-string, array<string, string>> */
	private static array $mappedSourceNames = [];

	public function findSourceProperty(MappingNode $node): ?ReflectionProperty
	{
		$source = $node->getParentSource();
		$name = $node->getName();

		if (! is_object($source) || ! is_string($name)) {
			return null;
		}

		$class = $source::class;
		if (array_key_exists($name, self::$sourceProperties[$class] ?? [])) {
			return self::$sourceProperties[$class][$name];
		}

		$reflection = new ReflectionClass($class);
		if (! $reflection->hasProperty($name)) {
			return null;
		}

		return self::$sourceProperties[$class][$name] = $reflection->getProperty($name);
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
		if (is_object($source)) {
			$class = $source::class;
			if (isset(self::$mappedSourceNames[$class][$propertyName])) {
				return self::$mappedSourceNames[$class][$propertyName];
			}
		}

		$attributes = $sourceProperty->getAttributes(MapTo::class);
		if ($attributes === []) {
			$mappedSourceName = $propertyName;
		} else {
			$mappedSourceName = $attributes[0]->newInstance()->getName();
		}

		if (is_object($source)) {
			self::$mappedSourceNames[$source::class][$propertyName] = $mappedSourceName;
		}

		return $mappedSourceName;
	}

	/**
	 * @param class-string $class
	 */
	private function matcherFor(string $class): ObjectPropertyMatcher
	{
		return self::$targetMatchers[$class]
			??= new ObjectPropertyMatcher(new ReflectionClass($class));
	}
}
