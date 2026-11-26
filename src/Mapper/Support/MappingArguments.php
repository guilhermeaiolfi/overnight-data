<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Support;

use ON\Data\Definition\DefinitionInterface;
use ON\Data\Mapper\Exception\MappingException;

/** @internal Direct arguments passed to a mapping operation. */
final class MappingArguments
{
	/**
	 * @template T of object
	 * @param list<mixed> $arguments
	 * @param class-string<T> $type
	 * @return list<T>
	 */
	public static function ofType(array $arguments, string $type): array
	{
		$matches = [];
		foreach ($arguments as $argument) {
			if ($argument instanceof $type) {
				$matches[] = $argument;
			}
		}

		return $matches;
	}

	/** @param list<mixed> $arguments */
	public static function definition(array $arguments): ?DefinitionInterface
	{
		$definitions = self::ofType($arguments, DefinitionInterface::class);
		if (count($definitions) < 2) {
			return $definitions[0] ?? null;
		}

		$names = array_map(
			static fn (DefinitionInterface $definition): string => sprintf('"%s"', $definition->getName()),
			$definitions,
		);

		throw new MappingException(sprintf(
			'Definition field resolution is ambiguous: mapping arguments contain %d definitions %s.',
			count($definitions),
			implode(' and ', $names),
		));
	}
}
