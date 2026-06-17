<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Resolver;

use ON\Data\Definition\DefinitionInterface;
use ON\Data\Mapper\Exception\MappingException;
use ON\Data\Mapper\FieldContext;
use ON\Data\Mapper\MappingNode;

final class DefinitionFieldResolver implements FieldResolverInterface
{
	private bool $discoveryComplete = false;

	private ?DefinitionInterface $definition = null;

	private ?MappingException $ambiguity = null;

	public function resolve(MappingNode $node): ?FieldContext
	{
		$definition = $this->getDefinition($node);
		if ($definition === null || ! is_string($node->getName())) {
			return null;
		}

		$field = $definition->getField($node->getName());
		if ($field === null) {
			return null;
		}

		return FieldContext::fromField($field);
	}

	private function getDefinition(MappingNode $node): ?DefinitionInterface
	{
		if (! $this->discoveryComplete) {
			$this->discoverDefinition($node);
		}

		if ($this->ambiguity !== null) {
			throw $this->ambiguity;
		}

		return $this->definition;
	}

	private function discoverDefinition(MappingNode $node): void
	{
		$this->discoveryComplete = true;

		$definitions = [];
		foreach ($node->getContext()->getArguments() as $argument) {
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
