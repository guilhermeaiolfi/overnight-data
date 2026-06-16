<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Field;

use ON\Data\Mapper\FieldContext;
use ON\Data\Mapper\FieldTypeInterface;

final class PassthroughFieldType implements FieldTypeInterface
{
	public static function storageType(): string
	{
		return 'text';
	}

	public static function toPhp(string $from, mixed $value, FieldContext $field): mixed
	{
		SupportedRepresentation::assert($from, static::class);

		return self::normalize($value);
	}

	public static function fromPhp(string $to, mixed $value, FieldContext $field): mixed
	{
		SupportedRepresentation::assert($to, static::class);

		return self::normalize($value);
	}

	private static function normalize(mixed $value): mixed
	{
		return $value;
	}
}
