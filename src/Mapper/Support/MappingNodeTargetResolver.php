<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Support;

use BackedEnum;
use DateTimeInterface;
use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\Representation\RepresentationInterface;
use ReflectionClass;
use ReflectionProperty;
use stdClass;

final class MappingNodeTargetResolver
{
	public function findTargetProperty(MappingNode $node): ?ReflectionProperty
	{
		$argument = $node->getArguments();
		$property = $this->extractReflectionProperty($argument);
		if ($property instanceof ReflectionProperty) {
			return $property;
		}

		$target = $node->getContext()->getTarget();
		if (! is_object($target) || $target instanceof stdClass) {
			return null;
		}

		$matcher = new ObjectPropertyMatcher(new ReflectionClass($target));

		return $matcher->match($node->getName());
	}

	public function resolveGenericChildTarget(MappingNode $node): mixed
	{
		$target = $node->getContext()->getTarget();
		if (is_array($target)) {
			return [];
		}

		if ($target instanceof stdClass || $target === stdClass::class) {
			return stdClass::class;
		}

		return null;
	}

	public function isStructuralValue(mixed $value): bool
	{
		if (is_array($value)) {
			return true;
		}

		return is_object($value) && ! $this->isExcludedObject($value);
	}

	public function isExcludedObject(object $value): bool
	{
		return $value instanceof DateTimeInterface
			|| $value instanceof BackedEnum
			|| $value instanceof RepresentationInterface;
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
