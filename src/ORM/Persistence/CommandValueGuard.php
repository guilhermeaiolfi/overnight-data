<?php

declare(strict_types=1);

namespace ON\Data\ORM\Persistence;

use ON\Data\ORM\Exception\InvalidCommandException;
use ON\Data\ORM\State\ValueRef;

final class CommandValueGuard
{
	/**
	 * @param array<string, mixed> $values
	 */
	public static function assertConcreteValues(string $command, string $slot, array $values): void
	{
		foreach ($values as $field => $value) {
			if (! $value instanceof ValueRef) {
				continue;
			}

			throw new InvalidCommandException(sprintf(
				"%s command %s cannot contain value reference for field '%s' referencing '%s.%s'.",
				$command,
				$slot,
				(string) $field,
				$value->getRecord()->getCollectionName(),
				$value->getField(),
			));
		}
	}
}
