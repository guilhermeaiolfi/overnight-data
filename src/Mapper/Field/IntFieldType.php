<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Field;

use InvalidArgumentException;
use ON\Data\Mapper\FieldContext;

final class IntFieldType extends AbstractPrimitiveFieldType
{
	public static function storageType(): string
	{
		return 'int';
	}

	protected static function normalizeToPhp(mixed $value, FieldContext $field): int
	{
		if (is_int($value)) {
			return $value;
		}

		if (is_float($value) && floor($value) === $value) {
			return (int) $value;
		}

		if (! is_string($value)) {
			throw new InvalidArgumentException(
				sprintf("Field '%s' expects an integer-compatible value.", $field->getName())
			);
		}

		$trimmed = trim($value);
		if ($trimmed === '' || preg_match('/^[+-]?\d+$/', $trimmed) !== 1) {
			throw new InvalidArgumentException(
				sprintf("Field '%s' cannot convert '%s' to int.", $field->getName(), $value)
			);
		}

		return (int) $trimmed;
	}

	protected static function normalizeFromPhp(mixed $value, FieldContext $field): int
	{
		return static::normalizeToPhp($value, $field);
	}
}
