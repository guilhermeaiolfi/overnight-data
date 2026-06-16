<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Field;

use ON\Data\Mapper\Exception\UnsupportedConversionException;
use ON\Data\Mapper\FieldContext;
use ON\Data\Mapper\FieldTypeInterface;
use ON\Data\Mapper\Representation\PhpRepresentation;
use ON\Data\Mapper\Representation\StorageRepresentation;
use ON\Data\Mapper\Representation\WireRepresentation;

abstract class AbstractPrimitiveFieldType implements FieldTypeInterface
{
	/**
	 * @param class-string $representation
	 */
	protected static function assertSupportedRepresentation(string $representation): void
	{
		if (
			$representation !== PhpRepresentation::class
			&& $representation !== StorageRepresentation::class
			&& $representation !== WireRepresentation::class
		) {
			throw new UnsupportedConversionException(
				sprintf("Representation '%s' is not supported by %s.", $representation, static::class)
			);
		}
	}

	abstract protected static function normalizeToPhp(mixed $value, FieldContext $field): mixed;

	abstract protected static function normalizeFromPhp(mixed $value, FieldContext $field): mixed;

	public static function toPhp(string $from, mixed $value, FieldContext $field): mixed
	{
		static::assertSupportedRepresentation($from);

		return static::normalizeToPhp($value, $field);
	}

	public static function fromPhp(string $to, mixed $value, FieldContext $field): mixed
	{
		static::assertSupportedRepresentation($to);

		return static::normalizeFromPhp($value, $field);
	}
}
