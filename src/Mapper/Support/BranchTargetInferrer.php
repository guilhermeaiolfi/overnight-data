<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Support;

use BackedEnum;
use DateTimeInterface;
use ON\Data\Definition\DefinitionInterface;
use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\Representation\RepresentationInterface;
use ReflectionNamedType;
use ReflectionProperty;
use stdClass;

final class BranchTargetInferrer
{
	public function __construct(
		private readonly ?MappingNodePropertyFinder $propertyFinder = null,
	) {
	}

	/**
	 * @return array{target: mixed, collection: bool, arguments: list<mixed>}|null
	 */
	public function inferFromReflection(MappingNode $node): ?array
	{
		$targetProperty = $this->finder()->findTargetProperty($node);
		if ($targetProperty !== null) {
			$target = $this->getTargetPropertyNestedTarget($node, $targetProperty);
			if ($target !== null) {
				return $target;
			}
		}

		$sourceProperty = $this->finder()->findSourceProperty($node);
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

	public function isStructuralValue(mixed $value): bool
	{
		if ($value === null) {
			return false;
		}

		if (is_array($value)) {
			return true;
		}

		return is_object($value) && ! $this->isExcludedObject($value);
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

		if ($type->getName() === 'array') {
			$listTarget = $this->resolvePhpDocListTarget($property);
			if ($listTarget !== null) {
				return $this->nestedTarget($listTarget, true, $this->withoutDirectDefinitions($node->getArguments()));
			}

			return $this->nestedTarget([], false, $this->withoutDirectDefinitions($node->getArguments()));
		}

		if ($type->getName() === 'object') {
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

		$type = $property->getType();
		if (! $type instanceof ReflectionNamedType) {
			return $this->nestedTarget($genericTarget, false, $node->getArguments());
		}

		if (! $type->isBuiltin()) {
			return $this->nestedTarget($genericTarget, false, $node->getArguments());
		}

		if ($type->getName() === 'array') {
			$listTarget = $this->resolvePhpDocListTarget($property);

			return $this->nestedTarget($genericTarget, $listTarget !== null, $node->getArguments());
		}

		if ($type->getName() === 'object') {
			return $this->nestedTarget($genericTarget, false, $node->getArguments());
		}

		return null;
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

	private function resolvePhpDocListTarget(ReflectionProperty $property): ?string
	{
		$doc = $property->getDocComment();
		if (! is_string($doc)) {
			return null;
		}

		if (preg_match('/@var\s+([\\\\A-Za-z_][\\\\A-Za-z0-9_]*)\[\]/u', $doc, $match)) {
			return $this->qualifyClassName($property, $match[1]);
		}

		if (preg_match('/@var\s+(?:list|array)<\s*([\\\\A-Za-z_][\\\\A-Za-z0-9_]*)\s*>/u', $doc, $match)) {
			return $this->qualifyClassName($property, $match[1]);
		}

		return null;
	}

	private function qualifyClassName(ReflectionProperty $property, string $type): ?string
	{
		$type = ltrim(trim($type), '\\');
		if ($type === '') {
			return null;
		}

		if (class_exists($type)) {
			return $type;
		}

		$namespace = $property->getDeclaringClass()->getNamespaceName();
		$qualified = $namespace === '' ? $type : $namespace . '\\' . $type;

		return class_exists($qualified) ? $qualified : null;
	}

	private function isExcludedObject(object $value): bool
	{
		return $value instanceof DateTimeInterface
			|| $value instanceof BackedEnum
			|| $value instanceof RepresentationInterface;
	}

	private function finder(): MappingNodePropertyFinder
	{
		return $this->propertyFinder ?? new MappingNodePropertyFinder();
	}
}
