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
		$target = $node->getParentTarget();
		if (! is_object($target) || $target instanceof stdClass) {
			return null;
		}

		return $this->matcherFor($target::class)->match(
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

	/**
	 * @param class-string $class
	 */
	private function matcherFor(string $class): ObjectPropertyMatcher
	{
		return $this->targetMatchers[$class]
			??= new ObjectPropertyMatcher(new ReflectionClass($class));
	}
}
