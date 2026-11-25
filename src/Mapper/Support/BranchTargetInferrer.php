<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Support;

use BackedEnum;
use DateTimeInterface;
use ON\Data\Definition\DefinitionInterface;
use ON\Data\Mapper\Mapper\ObjectMapperOptions;
use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\Representation\RepresentationInterface;
use ReflectionNamedType;
use ReflectionProperty;
use stdClass;

final class BranchTargetInferrer
{
	public function __construct(
		private readonly ?MappingNodePropertyFinder $propertyFinder = null,
		private readonly DocblockListTargetResolver $listTargetResolver = new DocblockListTargetResolver(),
	) {
	}

	/**
	 * @return array{target: mixed, collection: bool, arguments: list<mixed>}|null
	 */
	public function inferFromReflection(
		MappingNode $node,
		MappingNodePropertyFinder $propertyFinder,
	): ?array {
		$finder = $this->propertyFinder ?? $propertyFinder;
		$targetProperty = $finder->findTargetProperty($node);
		if ($targetProperty !== null) {
			$target = $this->getTargetPropertyNestedTarget($node, $targetProperty);
			if ($target !== null) {
				return $target;
			}
		}

		$sourceProperty = $finder->findSourceProperty($node);
		if ($sourceProperty !== null) {
			return $this->getSourcePropertyNestedTarget($node, $sourceProperty);
		}

		return null;
	}

	public function inferGenericTarget(mixed $target): mixed
	{
		if (is_array($target)) {
			return [];
		}

		if ($target instanceof stdClass || $target === stdClass::class) {
			return stdClass::class;
		}

		return null;
	}

	public static function isStructuralValue(mixed $value): bool
	{
		if ($value === null) {
			return false;
		}

		if (is_array($value)) {
			return true;
		}

		return is_object($value)
			&& ! $value instanceof DateTimeInterface
			&& ! $value instanceof BackedEnum
			&& ! $value instanceof RepresentationInterface;
	}

	/**
	 * Values that rematerialize under a stdClass target when nested object
	 * conversion is disabled: arrays and nested stdClass bags only.
	 */
	public static function isStdClassBagValue(mixed $value): bool
	{
		return is_array($value) || $value instanceof stdClass;
	}

	/**
	 * @param list<mixed> $value
	 */
	public static function isStdClassBagList(array $value): bool
	{
		foreach ($value as $item) {
			if ($item === null) {
				continue;
			}

			if (! self::isStdClassBagValue($item)) {
				return false;
			}
		}

		return true;
	}

	public static function isStdClassTarget(mixed $target): bool
	{
		return $target instanceof stdClass || $target === stdClass::class;
	}

	/**
	 * @param list<mixed> $arguments
	 *
	 * @return list<mixed>
	 */
	public function replaceDefinitionArguments(
		array $arguments,
		?DefinitionInterface $current,
		DefinitionInterface $replacement,
	): array {
		$replaced = false;
		$result = [];

		foreach ($arguments as $argument) {
			if ($argument instanceof DefinitionInterface) {
				if ($current !== null && $argument === $current && ! $replaced) {
					$result[] = $replacement;
					$replaced = true;
				}

				continue;
			}

			$result[] = $argument;
		}

		if (! $replaced) {
			$result[] = $replacement;
		}

		return $result;
	}

	/**
	 * @return array{target: mixed, collection: bool, arguments: list<mixed>}|null
	 */
	private function getTargetPropertyNestedTarget(
		MappingNode $node,
		ReflectionProperty $property,
	): ?array {
		$type = $property->getType();
		if (! $type instanceof ReflectionNamedType) {
			return null;
		}

		if (! $type->isBuiltin()) {
			$class = $type->getName();

			if (is_object($node->getValue()) && $node->getValue() instanceof $class) {
				return null;
			}

			return $this->nestedTarget($class, false, $this->withoutDirectDefinitions($node->getArguments()));
		}

		$name = $type->getName();
		if ($name === 'array' || $name === 'iterable') {
			$listTarget = $this->listTargetResolver->resolve($property);
			if ($listTarget !== null) {
				return $this->nestedTarget($listTarget, true, $this->withoutDirectDefinitions($node->getArguments()));
			}

			return $name === 'array'
				? $this->nestedTarget([], false, $this->withoutDirectDefinitions($node->getArguments()))
				: null;
		}

		if ($name === 'object') {
			return $this->nestedTarget(stdClass::class, false, $this->withoutDirectDefinitions($node->getArguments()));
		}

		return null;
	}

	/**
	 * @return array{target: mixed, collection: bool, arguments: list<mixed>}|null
	 */
	private function getSourcePropertyNestedTarget(
		MappingNode $node,
		ReflectionProperty $property,
	): ?array {
		$genericTarget = $this->inferGenericTarget($node->getParentTarget());
		if ($genericTarget === null) {
			return null;
		}

		if (
			$genericTarget === stdClass::class
			&& ! ObjectMapperOptions::fromArguments($node->getArguments())->convertsNestedObjects()
			&& ! $this->shouldRemapUnderStdClass($node->getValue())
		) {
			return null;
		}

		$type = $property->getType();
		if (! $type instanceof ReflectionNamedType) {
			return $this->nestedTarget($genericTarget, false, $node->getArguments());
		}

		if (! $type->isBuiltin()) {
			return $this->nestedTarget($genericTarget, false, $node->getArguments());
		}

		$name = $type->getName();
		if ($name === 'array' || $name === 'iterable') {
			$listTarget = $this->listTargetResolver->resolve($property);

			if ($name === 'array' || $listTarget !== null) {
				return $this->nestedTarget($genericTarget, $listTarget !== null, $node->getArguments());
			}
		}

		if ($name === 'object') {
			return $this->nestedTarget($genericTarget, false, $node->getArguments());
		}

		return null;
	}

	private function shouldRemapUnderStdClass(mixed $value): bool
	{
		if (is_array($value) && array_is_list($value)) {
			return $value !== [] && self::isStdClassBagList($value);
		}

		return self::isStdClassBagValue($value);
	}

	/**
	 * @param list<mixed> $arguments
	 *
	 * @return array{target: mixed, collection: bool, arguments: list<mixed>}
	 */
	private function nestedTarget(
		mixed $target,
		bool $collection,
		array $arguments,
	): array {
		return [
			'target' => $target,
			'collection' => $collection,
			'arguments' => $arguments,
		];
	}

	/**
	 * @param list<mixed> $arguments
	 *
	 * @return list<mixed>
	 */
	private function withoutDirectDefinitions(array $arguments): array
	{
		return array_values(array_filter(
			$arguments,
			static fn (mixed $argument): bool => ! $argument instanceof DefinitionInterface,
		));
	}
}
