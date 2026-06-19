<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Field;

use InvalidArgumentException;
use ON\Data\Mapper\FieldTypeInterface;
use ON\Data\Mapper\Resolution\LeafNodeResolutionInterface;

final class FloatFieldType implements FieldTypeInterface
{
	public static function getNames(): array
	{
		return ['float', 'double'];
	}

	public static function getStorageType(): string
	{
		return 'float';
	}

	public static function toPhp(mixed $value, LeafNodeResolutionInterface $field): mixed
	{
		return self::convertToFloat($value, $field);
	}

	public static function fromPhp(mixed $value, LeafNodeResolutionInterface $field): mixed
	{
		return self::convertToFloat($value, $field);
	}

	private static function convertToFloat(mixed $value, LeafNodeResolutionInterface $field): float
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
}
