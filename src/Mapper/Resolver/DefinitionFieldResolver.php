<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Resolver;

use ON\Data\Definition\DefinitionInterface;
use ON\Data\Mapper\Exception\MappingException;
use ON\Data\Mapper\FieldContext;
use ON\Data\Mapper\MappingContext;

final class DefinitionFieldResolver implements FieldResolverInterface
{
	private bool $discoveryComplete = false;

	private ?DefinitionInterface $definition = null;

	private ?MappingException $ambiguity = null;

	public function resolve(
		MappingContext $mapping,
		string $path,
		string|int $fieldName,
		mixed $value,
		mixed $extra = null,
	): ?FieldContext {
		$definition = $this->getDefinition($mapping);
		if ($definition === null || ! is_string($fieldName)) {
			return null;
		}

		$field = $definition->getField($fieldName);
		if ($field === null) {
			return null;
		}

		return FieldContext::fromField($field);
	}

	private function getDefinition(MappingContext $mapping): ?DefinitionInterface
	{
		if (! $this->discoveryComplete) {
			$this->discoverDefinition($mapping);
		}

		if ($this->ambiguity !== null) {
			throw $this->ambiguity;
		}

		return $this->definition;
	}

	private function discoverDefinition(MappingContext $mapping): void
	{
		$this->discoveryComplete = true;

		$definitions = [];
		foreach ($mapping->getArguments() as $argument) {
			if ($argument instanceof DefinitionInterface) {
				$definitions[] = $argument;
			}
		}

		if ($definitions === []) {
			return;
		}

		if (count($definitions) === 1) {
			$this->definition = $definitions[0];

			return;
		}

		$names = array_map(
			static fn (DefinitionInterface $definition): string => sprintf('"%s"', $definition->getName()),
			$definitions,
		);

		$this->ambiguity = new MappingException(
			sprintf(
				'Definition field resolution is ambiguous: mapping arguments contain %d definitions %s.',
				count($definitions),
				implode(' and ', $names),
			),
		);
	}
}
