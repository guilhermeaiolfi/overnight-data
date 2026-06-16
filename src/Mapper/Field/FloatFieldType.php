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
			if (! is_finite($value)) {
				throw new InvalidArgumentException(
					sprintf("Field '%s' cannot convert non-finite float '%s'.", $field->getName(), (string) $value)
				);
			}

			return $value;
		}

		if (is_int($value)) {
			$normalized = (float) $value;
			if (! is_finite($normalized)) {
				throw new InvalidArgumentException(
					sprintf("Field '%s' cannot convert non-finite float '%s'.", $field->getName(), (string) $value)
				);
			}

			return $normalized;
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

		$normalized = (float) $trimmed;
		if (! is_finite($normalized)) {
			throw new InvalidArgumentException(
				sprintf("Field '%s' cannot convert non-finite float '%s'.", $field->getName(), $value)
			);
		}

		return $normalized;
	}

	protected static function normalizeFromPhp(mixed $value, FieldContext $field): float
	{
		return static::normalizeToPhp($value, $field);
	}
}
