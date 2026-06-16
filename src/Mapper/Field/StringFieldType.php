<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Field;

use InvalidArgumentException;
use ON\Data\Mapper\FieldContext;

final class StringFieldType extends AbstractPrimitiveFieldType
{
	public static function storageType(): string
	{
		return 'string';
	}

	protected static function normalizeToPhp(mixed $value, FieldContext $field): string
	{
		if (is_string($value)) {
			return $value;
		}

		if (is_int($value) || is_float($value) || is_bool($value)) {
			return (string) $value;
		}

		throw new InvalidArgumentException(
			sprintf("Field '%s' expects a string-compatible value.", $field->getName())
		);
	}

	protected static function normalizeFromPhp(mixed $value, FieldContext $field): string
	{
		return static::normalizeToPhp($value, $field);
	}
}
