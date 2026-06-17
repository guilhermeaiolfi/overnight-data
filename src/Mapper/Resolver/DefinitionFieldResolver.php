<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Resolver;

use ON\Data\Definition\DefinitionInterface;
use ON\Data\Mapper\Exception\MappingException;
use ON\Data\Mapper\FieldContext;
use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\Support\DefinitionArgumentLocator;

final class DefinitionFieldResolver implements FieldResolverInterface
{
	private bool $discoveryComplete = false;

	private ?DefinitionInterface $definition = null;

	private ?MappingException $ambiguity = null;

	public function __construct(
		private readonly ?DefinitionArgumentLocator $locator = null,
	) {
	}

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

		try {
			$this->definition = ($this->locator ?? new DefinitionArgumentLocator())
				->getDefinition($node->getArguments());
		} catch (MappingException $exception) {
			$this->ambiguity = $exception;
		}
	}
}
