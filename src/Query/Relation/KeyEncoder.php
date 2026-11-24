<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation;

use ON\Data\Query\Exception\LoadRuntimeException;

/**
 * Deterministic scalar/tuple encoder for record identity and relation key matching.
 *
 * Integer strings (e.g. MySQL derived/window columns returned as strings) encode
 * identically to native integers so separate-query correlation matches reliably.
 */
final class KeyEncoder
{
	private function __construct()
	{
	}

	public static function encodeValue(mixed $value): string
	{
		return match (true) {
			is_int($value) => 'n:' . $value,
			is_string($value) => self::encodeString($value),
			is_float($value) => 'f:' . serialize($value),
			is_bool($value) => 'b:' . ($value ? '1' : '0'),
			default => throw LoadRuntimeException::nonScalarKeyValue('(value)', $value),
		};
	}

	/**
	 * @param list<scalar> $values
	 */
	public static function encodeTuple(array $values): string
	{
		$key = '';

		foreach ($values as $value) {
			$encoded = self::encodeValue($value);
			$key .= strlen($encoded) . ':' . $encoded . ';';
		}

		return $key;
	}

	/**
	 * Extract ordered scalar values for $fields from $source. Returns null if any
	 * configured key field is null.
	 *
	 * @param list<string> $fields
	 * @param array<string, mixed> $source
	 * @return list<scalar>|null
	 */
	public static function extractTuple(array $fields, array $source): ?array
	{
		$values = [];

		foreach ($fields as $field) {
			if (! array_key_exists($field, $source)) {
				throw LoadRuntimeException::missingRecordField($field);
			}

			$value = $source[$field];

			if ($value === null) {
				return null;
			}

			if (! is_scalar($value)) {
				throw LoadRuntimeException::nonScalarKeyValue($field, $value);
			}

			$values[] = $value;
		}

		return $values;
	}

	private static function encodeString(string $value): string
	{
		if (preg_match('/^-?(0|[1-9]\d*)$/', $value) === 1) {
			return 'n:' . $value;
		}

		return 's:' . strlen($value) . ':' . $value;
	}
}
