<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Walker;

use BackedEnum;
use DateTimeInterface;
use ON\Data\Definition\DefinitionInterface;
use ON\Data\Definition\Relation\RelationInterface;
use ON\Data\Mapper\Exception\MappingException;
use ON\Data\Mapper\MapperManager;
use ON\Data\Mapper\MappingContext;
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
		mixed $source,
		mixed $target,
		MappingContext $context,
		MapperManager $mappers,
	): mixed {
		if ($context->isCollection()) {
			if (! is_iterable($source)) {
				throw new MappingException('Collection mapping requires an iterable source.');
			}

			$results = [];
			foreach ($source as $key => $item) {
				if (
					is_string($target)
					&& $target !== stdClass::class
					&& is_object($item)
					&& $item instanceof $target
				) {
					$results[] = $item;

					continue;
				}

				$results[] = $mappers->map(
					$item,
					$target,
					$context
						->forChild($item, $context->getArguments(), false, true)
						->withPathSegment((string) $key),
				);
			}

			return $results;
		}

		$writer = $mappers->resolveWriter($target, $context);
		$result = $writer->prepare($target, $context);
		$levelContext = $context->enter($source, $result);
		$fieldCoordinator = $mappers->createFieldConversionCoordinator($levelContext);

		foreach ($this->getNodes($source, $levelContext) as $node) {
			$node = $node->withContext($levelContext->withPathSegment((string) $node->getName()));
			$nestedMapping = $this->getNestedMapping($node);

			if ($nestedMapping !== null && $node->getValue() !== null) {
				$value = $mappers->map(
					$node->getValue(),
					$nestedMapping->getTarget(),
					$levelContext->forChild(
						$node->getValue(),
						$nestedMapping->getArguments(),
						$nestedMapping->isCollection(),
					)->withPath($node->getContext()->getPath()),
				);
			} else {
				$field = $fieldCoordinator->resolveField($node);
				$value = $fieldCoordinator->convertScalar($node->getValue(), $field, $node->getContext());
			}

			$result = $writer->write($result, $node, $value);
			$levelContext = $levelContext->enter($source, $result);
		}

		return $writer->finish($result, $levelContext);
	}

	/**
	 * @return iterable<MappingNode>
	 */
	abstract protected function getNodes(
		mixed $source,
		MappingContext $context,
	): iterable;

	private function getNestedMapping(MappingNode $node): ?NestedMapping
	{
		if (! $this->isStructuralValue($node->getValue())) {
			return null;
		}

		$propertyFinder = new MappingNodePropertyFinder();
		$targetProperty = $propertyFinder->findTargetProperty($node);
		$sourceProperty = $propertyFinder->findSourceProperty($node);
		$activeDefinition = $this->getActiveDefinition($node->getContext()->getArguments());
		$relation = $this->getRelation($activeDefinition, $node);

		$reflectionMapping = $this->getReflectionNestedMapping($node, $targetProperty, $sourceProperty);
		$genericTarget = $this->getGenericChildTarget($node->getContext()->getTarget());

		if ($relation !== null) {
			$target = $reflectionMapping?->getTarget() ?? $genericTarget;
			if ($target === null) {
				return null;
			}

			return new NestedMapping(
				$target,
				$relation->getCardinality() === 'many',
				$this->replaceDefinitionArguments(
					$node->getContext()->getArguments(),
					$activeDefinition,
					$relation->getCollection(),
				),
			);
		}

		if ($reflectionMapping !== null) {
			return $reflectionMapping;
		}

		if ($genericTarget === null) {
			return null;
		}

		return new NestedMapping($genericTarget, false, $node->getContext()->getArguments());
	}

	private function getReflectionNestedMapping(
		MappingNode $node,
		?ReflectionProperty $targetProperty,
		?ReflectionProperty $sourceProperty,
	): ?NestedMapping {
		if ($targetProperty !== null) {
			$mapping = $this->getTargetPropertyNestedMapping($node, $targetProperty);
			if ($mapping !== null) {
				return $mapping;
			}
		}

		if ($sourceProperty !== null) {
			return $this->getSourcePropertyNestedMapping($node, $sourceProperty);
		}

		return null;
	}

	private function getTargetPropertyNestedMapping(
		MappingNode $node,
		ReflectionProperty $property,
	): ?NestedMapping {
		$type = $property->getType();
		if (! $type instanceof ReflectionNamedType) {
			return null;
		}

		if (! $type->isBuiltin()) {
			$class = $type->getName();

			if (is_object($node->getValue()) && $node->getValue() instanceof $class) {
				return null;
			}

			return new NestedMapping(
				$class,
				false,
				$this->withoutDirectDefinitions($node->getContext()->getArguments()),
			);
		}

		if ($type->getName() === 'array') {
			$listTarget = $this->resolvePhpDocListTarget($property);
			if ($listTarget !== null) {
				return new NestedMapping(
					$listTarget,
					true,
					$this->withoutDirectDefinitions($node->getContext()->getArguments()),
				);
			}

			return new NestedMapping(
				[],
				false,
				$this->withoutDirectDefinitions($node->getContext()->getArguments()),
			);
		}

		if ($type->getName() === 'object') {
			return new NestedMapping(
				stdClass::class,
				false,
				$this->withoutDirectDefinitions($node->getContext()->getArguments()),
			);
		}

		return null;
	}

	private function getSourcePropertyNestedMapping(
		MappingNode $node,
		ReflectionProperty $property,
	): ?NestedMapping {
		$genericTarget = $this->getGenericChildTarget($node->getContext()->getTarget());
		if ($genericTarget === null) {
			return null;
		}

		$type = $property->getType();
		if (! $type instanceof ReflectionNamedType) {
			return new NestedMapping($genericTarget, false, $node->getContext()->getArguments());
		}

		if (! $type->isBuiltin()) {
			return new NestedMapping($genericTarget, false, $node->getContext()->getArguments());
		}

		if ($type->getName() === 'array') {
			$listTarget = $this->resolvePhpDocListTarget($property);
			if ($listTarget !== null) {
				return new NestedMapping($genericTarget, true, $node->getContext()->getArguments());
			}

			return new NestedMapping($genericTarget, false, $node->getContext()->getArguments());
		}

		if ($type->getName() === 'object') {
			return new NestedMapping($genericTarget, false, $node->getContext()->getArguments());
		}

		return null;
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
