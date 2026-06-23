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
	/**
	 * @var array<class-string, array<string, class-string|null>>
	 */
	private array $phpDocListTargets = [];

	/**
	 * @var array<class-string, array<string, class-string>>
	 */
	private array $importMaps = [];

	public function __construct(
		private readonly ?MappingNodePropertyFinder $propertyFinder = null,
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
		$class = $property->getDeclaringClass()->getName();
		$name = $property->getName();

		if (array_key_exists($name, $this->phpDocListTargets[$class] ?? [])) {
			return $this->phpDocListTargets[$class][$name];
		}

		return $this->phpDocListTargets[$class][$name]
			= $this->parsePhpDocListTarget($property);
	}

	private function parsePhpDocListTarget(ReflectionProperty $property): ?string
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

		$import = $this->resolveImportedClassName($property, $type);
		if ($import !== null && class_exists($import)) {
			return $import;
		}

		$namespace = $property->getDeclaringClass()->getNamespaceName();
		$qualified = $namespace === '' ? $type : $namespace . '\\' . $type;

		return class_exists($qualified) ? $qualified : null;
	}

	private function resolveImportedClassName(ReflectionProperty $property, string $type): ?string
	{
		if (str_contains($type, '\\')) {
			return null;
		}

		$class = $property->getDeclaringClass()->getName();
		$imports = $this->importMaps[$class] ??= $this->parseImports($property);

		return $imports[$type] ?? null;
	}

	/**
	 * @return array<string, class-string>
	 */
	private function parseImports(ReflectionProperty $property): array
	{
		$file = $property->getDeclaringClass()->getFileName();
		if (! is_string($file) || $file === '') {
			return [];
		}

		$contents = file_get_contents($file);
		if (! is_string($contents)) {
			return [];
		}

		$tokens = token_get_all($contents);
		$imports = [];
		$depth = 0;
		$count = count($tokens);

		for ($index = 0; $index < $count; $index++) {
			$token = $tokens[$index];

			if ($token === '{') {
				$depth++;

				continue;
			}

			if ($token === '}') {
				$depth = max(0, $depth - 1);

				continue;
			}

			if (! is_array($token) || $depth !== 0 || $token[0] !== T_USE) {
				continue;
			}

			$import = '';
			$alias = null;
			$parsingAlias = false;

			for ($index++; $index < $count; $index++) {
				$current = $tokens[$index];

				if ($current === ';') {
					break;
				}

				if (! is_array($current)) {
					continue;
				}

				if (in_array($current[0], [T_STRING, T_NS_SEPARATOR, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
					if ($parsingAlias) {
						$alias ??= '';
						$alias .= $current[1];
					} else {
						$import .= $current[1];
					}

					continue;
				}

				if ($current[0] === T_AS) {
					$parsingAlias = true;
				}
			}

			$import = ltrim($import, '\\');
			if ($import === '') {
				continue;
			}

			$shortName = $alias;
			if ($shortName === null || $shortName === '') {
				$parts = explode('\\', $import);
				$shortName = end($parts);
			}

			if (is_string($shortName) && $shortName !== '') {
				$imports[$shortName] = $import;
			}
		}

		return $imports;
	}
}
