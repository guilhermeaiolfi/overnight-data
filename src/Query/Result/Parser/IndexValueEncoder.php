<?php

declare(strict_types=1);

namespace ON\Data\Query\Result\Parser;

final class IndexValueEncoder
{
	private function __construct()
	{
	}

	public static function encodeIndexValue(mixed $value): string
	{
		return match (true) {
			is_int($value) => 'n:' . $value,
			is_string($value) => self::encodeString($value),
			is_float($value) => 'f:' . serialize($value),
			is_bool($value) => 'b:' . ($value ? '1' : '0'),
			default => throw new ParserException(sprintf(
				'Non-scalar identity or reference value of type `%s` is not supported.',
				get_debug_type($value),
			)),
		};
	}

	private static function encodeString(string $value): string
	{
		// MySQL drivers (especially derived tables / window queries) often return integer
		// columns as strings while parent rows keep native ints. Reference indexes must
		// treat those as the same key or separate-query relation mounts fail to attach.
		if (preg_match('/^-?(0|[1-9]\d*)$/', $value) === 1) {
			return 'n:' . $value;
		}

		return 's:' . strlen($value) . ':' . $value;
	}
}
