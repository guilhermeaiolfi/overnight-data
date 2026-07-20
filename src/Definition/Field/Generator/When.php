<?php

declare(strict_types=1);

namespace ON\Data\Definition\Field\Generator;

/**
 * Bitmask for when a field generator participates in persistence.
 */
final class When
{
	public const INSERT = 1;

	public const UPDATE = 2;

	public static function includes(int $when, int $flag): bool
	{
		return ($when & $flag) === $flag;
	}
}
