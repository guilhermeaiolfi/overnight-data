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
		$definition = null;

		foreach ($arguments as $argument) {
			if (! $argument instanceof DefinitionInterface) {
				continue;
			}

			if ($definition !== null) {
				throw $this->ambiguousDefinition($arguments);
			}

			$definition = $argument;
		}

		return $definition;
	}

	/**
	 * @param list<mixed> $arguments
	 */
	private function ambiguousDefinition(array $arguments): MappingException
	{
		$definitions = [];

		foreach ($arguments as $argument) {
			if ($argument instanceof DefinitionInterface) {
				$definitions[] = $argument;
			}
		}

		$names = array_map(
			static fn (DefinitionInterface $definition): string => sprintf('"%s"', $definition->getName()),
			$definitions,
		);

		return new MappingException(sprintf(
			'Definition field resolution is ambiguous: mapping arguments contain %d definitions %s.',
			count($definitions),
			implode(' and ', $names),
		));
	}
}
