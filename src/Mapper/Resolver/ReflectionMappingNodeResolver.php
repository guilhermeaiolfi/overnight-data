<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Resolver;

use ON\Data\Definition\DefinitionInterface;
use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\Support\MappingNodeTargetResolver;
use ReflectionNamedType;
use ReflectionProperty;
use stdClass;

final class ReflectionMappingNodeResolver implements MappingNodeResolverInterface
{
	public function __construct(
		private readonly ?MappingNodeTargetResolver $targetResolver = null,
	) {
	}

	public function resolve(MappingNode $node): ?MappingNode
	{
		$resolver = $this->targetResolver ?? new MappingNodeTargetResolver();
		$property = $resolver->findTargetProperty($node);
		if (! $property instanceof ReflectionProperty) {
			return null;
		}

		$type = $property->getType();
		if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
			$class = $type->getName();

			if ($node->getValue() instanceof $class) {
				return null;
			}

			return $node->forChild(
				target: $class,
				collection: false,
				arguments: $this->withoutDirectDefinitions($node->getContext()->getArguments()),
			);
		}

		if ($type instanceof ReflectionNamedType && $type->isBuiltin()) {
			if ($type->getName() === 'array') {
				$listTarget = $this->resolvePhpDocListTarget($property);
				if ($listTarget !== null) {
					return $node->forChild(
						target: $listTarget,
						collection: true,
						arguments: $this->withoutDirectDefinitions($node->getContext()->getArguments()),
					);
				}

				if ($resolver->isStructuralValue($node->getValue())) {
					return $node->forChild(
						target: [],
						collection: false,
						arguments: $this->withoutDirectDefinitions($node->getContext()->getArguments()),
					);
				}
			}

			if ($type->getName() === 'object' && $resolver->isStructuralValue($node->getValue())) {
				return $node->forChild(
					target: stdClass::class,
					collection: false,
					arguments: $this->withoutDirectDefinitions($node->getContext()->getArguments()),
				);
			}
		}

		return null;
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
