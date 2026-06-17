<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Walker;

use BackedEnum;
use DateTimeInterface;
use ON\Data\Definition\DefinitionInterface;
use ON\Data\Definition\Relation\RelationInterface;
use ON\Data\Mapper\Exception\MappingException;
use ON\Data\Mapper\MapperManager;
use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\Representation\RepresentationInterface;
use ON\Data\Mapper\Support\DefinitionArgumentLocator;
use ON\Data\Mapper\Support\MappingNodePropertyFinder;
use ReflectionNamedType;
use ReflectionProperty;
use stdClass;

abstract class Walker implements WalkerInterface
{
	final public function walk(
		MappingNode $node,
		MapperManager $mappers,
	): mixed {
		if ($node->isCollection()) {
			if (! is_iterable($node->getValue())) {
				throw new MappingException('Collection mapping requires an iterable source.');
			}

			$results = [];
			foreach ($node->getValue() as $key => $item) {
				$targetClass = is_string($node->getTarget()) ? $node->getTarget() : null;

				if (
					$targetClass !== null
					&& $targetClass !== stdClass::class
					&& is_object($item)
					&& $item instanceof $targetClass
				) {
					$results[] = $item;

					continue;
				}

				$results[] = $mappers->mapNode(
					$node
						->child((string) $key, $item)
						->forMapping($node->getTarget(), $node->getArguments(), false, true),
				);
			}

			return $results;
		}

		$writer = $mappers->resolveWriter($node->getTarget(), $node->getContext());
		$result = $writer->prepare($node->getTarget(), $node->getContext());
		$frame = $node->withTarget($result);
		$fieldCoordinator = $mappers->createFieldConversionCoordinator($frame->getContext());

		foreach ($this->getNodes($frame) as $child) {
			$nestedNode = $this->getNestedNode($child);

			if ($nestedNode !== null && $child->getValue() !== null) {
				$value = $mappers->mapNode($nestedNode);
			} else {
				$field = $fieldCoordinator->resolveField($child);
				$value = $fieldCoordinator->convertScalar($child->getValue(), $field, $child);
			}

			$result = $writer->write($result, $child, $value);
		}

		return $writer->finish($result, $frame->getContext());
	}

	/**
	 * @return iterable<MappingNode>
	 */
	abstract protected function getNodes(MappingNode $node): iterable;

	private function getNestedNode(MappingNode $node): ?MappingNode
	{
		if (! $this->isStructuralValue($node->getValue())) {
			return null;
		}

		$propertyFinder = new MappingNodePropertyFinder();
		$targetProperty = $propertyFinder->findTargetProperty($node);
		$sourceProperty = $propertyFinder->findSourceProperty($node);
		$activeDefinition = $this->getActiveDefinition($node->getArguments());
		$relation = $this->getRelation($activeDefinition, $node);
		$reflectionTarget = $this->getReflectionNestedTarget($node, $targetProperty, $sourceProperty);
		$genericTarget = $this->getGenericChildTarget($node->getParentTarget());

		if ($relation !== null) {
			$target = $reflectionTarget['target'] ?? $genericTarget;
			if ($target === null) {
				return null;
			}

			return $node->forMapping(
				$target,
				$this->replaceDefinitionArguments(
					$node->getArguments(),
					$activeDefinition,
					$relation->getCollection(),
				),
				$relation->getCardinality() === 'many',
			);
		}

		if ($reflectionTarget !== null) {
			return $node->forMapping(
				$reflectionTarget['target'],
				$reflectionTarget['arguments'],
				$reflectionTarget['collection'],
			);
		}

		if ($genericTarget === null) {
			return null;
		}

		return $node->forMapping($genericTarget, $node->getArguments());
	}

	/**
	 * @return array{target: mixed, collection: bool, arguments: list<mixed>}|null
	 */
	private function getReflectionNestedTarget(
		MappingNode $node,
		?ReflectionProperty $targetProperty,
		?ReflectionProperty $sourceProperty,
	): ?array {
		if ($targetProperty !== null) {
			$target = $this->getTargetPropertyNestedTarget($node, $targetProperty);
			if ($target !== null) {
				return $target;
			}
		}

		if ($sourceProperty !== null) {
			return $this->getSourcePropertyNestedTarget($node, $sourceProperty);
		}

		return null;
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
		$genericTarget = $this->getGenericChildTarget($node->getParentTarget());
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
			if ($listTarget !== null) {
				return $this->nestedTarget($genericTarget, true, $node->getArguments());
			}

			return $this->nestedTarget($genericTarget, false, $node->getArguments());
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

	private function getGenericChildTarget(mixed $target): mixed
	{
		if (is_array($target)) {
			return [];
		}

		if ($target instanceof stdClass || $target === stdClass::class) {
			return stdClass::class;
		}

		return null;
	}

	private function isStructuralValue(mixed $value): bool
	{
		if ($value === null) {
			return false;
		}

		if (is_array($value)) {
			return true;
		}

		return is_object($value) && ! $this->isExcludedObject($value);
	}

	private function isExcludedObject(object $value): bool
	{
		return $value instanceof DateTimeInterface
			|| $value instanceof BackedEnum
			|| $value instanceof RepresentationInterface;
	}

	/**
	 * @param list<mixed> $arguments
	 */
	private function getActiveDefinition(array $arguments): ?DefinitionInterface
	{
		return (new DefinitionArgumentLocator())->getDefinition($arguments);
	}

	private function getRelation(?DefinitionInterface $definition, MappingNode $node): ?RelationInterface
	{
		if ($definition === null || ! is_string($node->getName())) {
			return null;
		}

		return $definition->getRelation($node->getName());
	}

	/**
	 * @param list<mixed> $arguments
	 *
	 * @return list<mixed>
	 */
	private function replaceDefinitionArguments(
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
}
