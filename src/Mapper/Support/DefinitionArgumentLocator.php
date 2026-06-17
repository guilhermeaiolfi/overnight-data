<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Support;

use ON\Data\Definition\DefinitionInterface;
use ON\Data\Mapper\Exception\MappingException;

final class DefinitionArgumentLocator
{
	/**
	 * @param list<mixed> $arguments
	 */
	public function getDefinition(array $arguments): ?DefinitionInterface
	{
		$definitions = [];
		foreach ($arguments as $argument) {
			if ($argument instanceof DefinitionInterface) {
				$definitions[] = $argument;
			}
		}

		if ($definitions === []) {
			return null;
		}

		if (count($definitions) === 1) {
			return $definitions[0];
		}

		$names = array_map(
			static fn (DefinitionInterface $definition): string => sprintf('"%s"', $definition->getName()),
			$definitions,
		);

		throw new MappingException(
			sprintf(
				'Definition field resolution is ambiguous: mapping arguments contain %d definitions %s.',
				count($definitions),
				implode(' and ', $names),
			),
		);
	}
}
