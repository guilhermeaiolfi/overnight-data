<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Field;

use InvalidArgumentException;
use ON\Data\Mapper\FieldTypeInterface;
use ON\Data\Mapper\Resolution\LeafNodeResolutionInterface;

final class StringFieldType implements FieldTypeInterface
{
	public static function getNames(): array
	{
		return ['string'];
	}

	public static function getStorageType(): string
	{
		return 'string';
	}

	public static function toPhp(mixed $value, LeafNodeResolutionInterface $field): mixed
	{
		return self::convertToString($value, $field);
	}

	public static function fromPhp(mixed $value, LeafNodeResolutionInterface $field): mixed
	{
		return self::convertToString($value, $field);
	}

	private static function convertToString(mixed $value, LeafNodeResolutionInterface $field): string
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
