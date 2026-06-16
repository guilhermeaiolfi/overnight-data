<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Field;

use InvalidArgumentException;
use ON\Data\Mapper\FieldContext;
use ON\Data\Mapper\FieldTypeInterface;

final class BoolFieldType implements FieldTypeInterface
{
	public static function storageType(): string
	{
		return 'bool';
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

	private static function normalize(mixed $value, FieldContext $field): bool
	{
		if (is_bool($value)) {
			return $value;
		}

		if (is_int($value)) {
			return match ($value) {
				0 => false,
				1 => true,
				default => throw new InvalidArgumentException(
					sprintf("Field '%s' cannot convert integer '%d' to bool.", $field->getName(), $value)
				),
			};
		}

		if (is_float($value)) {
			return match ($value) {
				0.0 => false,
				1.0 => true,
				default => throw new InvalidArgumentException(
					sprintf("Field '%s' cannot convert float '%s' to bool.", $field->getName(), (string) $value)
				),
			};
		}

		if (! is_string($value)) {
			throw new InvalidArgumentException(
				sprintf("Field '%s' expects a boolean-compatible value.", $field->getName())
			);
		}

		return match (strtolower(trim($value))) {
			'1', 'true', 'yes', 'on' => true,
			'0', 'false', 'no', 'off' => false,
			default => throw new InvalidArgumentException(
				sprintf("Field '%s' cannot convert ambiguous boolean value '%s'.", $field->getName(), $value)
			),
		};
	}
}
