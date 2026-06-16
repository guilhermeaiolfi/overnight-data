<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Field;

use InvalidArgumentException;
use ON\Data\Mapper\FieldContext;

final class FloatFieldType extends AbstractPrimitiveFieldType
{
	public static function storageType(): string
	{
		return 'float';
	}

	protected static function normalizeToPhp(mixed $value, FieldContext $field): float
	{
		if (is_float($value)) {
			return $value;
		}

		if (is_int($value)) {
			return (float) $value;
		}

		if (! is_string($value)) {
			throw new InvalidArgumentException(
				sprintf("Field '%s' expects a float-compatible value.", $field->getName())
			);
		}

		$trimmed = trim($value);
		if ($trimmed === '' || ! is_numeric($trimmed)) {
			throw new InvalidArgumentException(
				sprintf("Field '%s' cannot convert '%s' to float.", $field->getName(), $value)
			);
		}

		return (float) $trimmed;
	}

	protected static function normalizeFromPhp(mixed $value, FieldContext $field): float
	{
		return static::normalizeToPhp($value, $field);
	}
}
