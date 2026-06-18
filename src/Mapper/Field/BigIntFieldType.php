<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Field;

use InvalidArgumentException;
use ON\Data\Mapper\FieldContext;
use ON\Data\Mapper\FieldTypeInterface;

final class BigIntFieldType implements FieldTypeInterface
{
	public static function getNames(): array
	{
		return ['bigint', 'biginteger', 'bigprimary'];
	}

	public static function getStorageType(): string
	{
		return 'bigint';
	}

	public static function toPhp(mixed $value, FieldContext $field): mixed
	{
		return self::convertToIntegerString($value, $field);
	}

	public static function fromPhp(mixed $value, FieldContext $field): mixed
	{
		return self::convertToIntegerString($value, $field);
	}

	private static function convertToIntegerString(mixed $value, FieldContext $field): string
	{
		if (is_int($value)) {
			$value = (string) $value;
		}

		if (! is_string($value)) {
			throw new InvalidArgumentException(
				sprintf("Field '%s' expects an integer string or integer.", $field->getName()),
			);
		}

		$normalized = trim($value);
		if ($normalized === '' || preg_match('/^[+-]?\d+$/', $normalized) !== 1) {
			throw new InvalidArgumentException(
				sprintf("Field '%s' expects a base-10 integer string; received '%s'.", $field->getName(), $value),
			);
		}

		if (str_starts_with($normalized, '+')) {
			$normalized = substr($normalized, 1);
		}

		$negative = str_starts_with($normalized, '-');
		if ($negative) {
			$normalized = substr($normalized, 1);
		}

		$normalized = ltrim($normalized, '0');
		$normalized = $normalized === '' ? '0' : $normalized;

		if ($normalized === '0') {
			return '0';
		}

		return $negative ? '-' . $normalized : $normalized;
	}
}
