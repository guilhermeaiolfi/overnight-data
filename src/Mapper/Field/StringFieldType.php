<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Field;

use InvalidArgumentException;
use ON\Data\Mapper\FieldContext;
use ON\Data\Mapper\FieldTypeInterface;

final class StringFieldType implements FieldTypeInterface
{
	public static function storageType(): string
	{
		return 'string';
	}

	public static function toPhp(string $from, mixed $value, FieldContext $field): mixed
	{
		SupportedRepresentation::assert($from, static::class);

		return self::normalize($value, $field);
	}

	public static function fromPhp(string $to, mixed $value, FieldContext $field): mixed
	{
		SupportedRepresentation::assert($to, static::class);

		return self::normalize($value, $field);
	}

	private static function normalize(mixed $value, FieldContext $field): string
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
}
