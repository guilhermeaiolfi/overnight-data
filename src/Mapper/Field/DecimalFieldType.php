<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Field;

use InvalidArgumentException;
use ON\Data\Mapper\FieldContext;
use ON\Data\Mapper\FieldTypeInterface;

final class DecimalFieldType implements FieldTypeInterface
{
	public static function getNames(): array
	{
		return ['decimal'];
	}

	public static function getStorageType(): string
	{
		return 'decimal';
	}

	public static function toPhp(mixed $value, FieldContext $field): mixed
	{
		return self::convertToDecimalString($value, $field);
	}

	public static function fromPhp(mixed $value, FieldContext $field): mixed
	{
		return self::convertToDecimalString($value, $field);
	}

	private static function convertToDecimalString(mixed $value, FieldContext $field): string
	{
		if (is_int($value)) {
			$value = (string) $value;
		}

		if (is_float($value)) {
			throw new InvalidArgumentException(
				sprintf(
					"Field '%s' expects decimal values as strings or integers to avoid precision loss.",
					$field->getName(),
				),
			);
		}

		if (! is_string($value)) {
			throw new InvalidArgumentException(
				sprintf("Field '%s' expects a decimal string or integer.", $field->getName()),
			);
		}

		$normalized = trim($value);
		if ($normalized === '' || preg_match('/^[+-]?\d+(?:\.\d+)?$/', $normalized) !== 1) {
			throw new InvalidArgumentException(
				sprintf("Field '%s' expects a base-10 decimal string; received '%s'.", $field->getName(), $value),
			);
		}

		if (str_starts_with($normalized, '+')) {
			$normalized = substr($normalized, 1);
		}

		$negative = str_starts_with($normalized, '-');
		if ($negative) {
			$normalized = substr($normalized, 1);
		}

		$parts = explode('.', $normalized, 2);
		$integer = ltrim($parts[0], '0');
		$integer = $integer === '' ? '0' : $integer;
		$fractional = $parts[1] ?? '';
		$fractional = rtrim($fractional, '0');

		$result = $fractional === ''
			? $integer
			: $integer . '.' . $fractional;

		if ($result === '0') {
			return '0';
		}

		return $negative ? '-' . $result : $result;
	}
}
