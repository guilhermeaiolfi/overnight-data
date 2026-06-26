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
			is_int($value) => 'i:' . $value,
			is_string($value) => 's:' . strlen($value) . ':' . $value,
			is_float($value) => 'f:' . serialize($value),
			is_bool($value) => 'b:' . ($value ? '1' : '0'),
			default => throw new ParserException(sprintf(
				'Non-scalar identity or reference value of type `%s` is not supported.',
				get_debug_type($value),
			)),
		};
	}
}
