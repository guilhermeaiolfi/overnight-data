<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Field;

use InvalidArgumentException;
use ON\Data\Mapper\FieldTypeInterface;
use ON\Data\Mapper\Resolution\LeafNodeResolutionInterface;

final class IntFieldType implements FieldTypeInterface
{
	private const FLOAT_SAFE_INTEGER_MAX = 9007199254740991;

	public static function getNames(): array
	{
		return ['int', 'integer', 'primary', 'smallprimary'];
	}

	public static function getStorageType(): string
	{
		return 'int';
	}

	public static function toPhp(mixed $value, LeafNodeResolutionInterface $field): mixed
	{
		return self::convertToInt($value, $field);
	}

	public static function fromPhp(mixed $value, LeafNodeResolutionInterface $field): mixed
	{
		return self::convertToInt($value, $field);
	}

	private static function convertToInt(mixed $value, LeafNodeResolutionInterface $field): int
	{
		if (is_int($value)) {
			return $value;
		}

		if (is_float($value)) {
			if (! is_finite($value) || floor($value) !== $value) {
				throw new InvalidArgumentException(
					sprintf("Field '%s' cannot convert '%s' to int.", $field->getName(), (string) $value)
				);
			}

			if ($value < PHP_INT_MIN || $value > PHP_INT_MAX) {
				throw new InvalidArgumentException(
					sprintf("Field '%s' cannot convert out-of-range float '%s' to int.", $field->getName(), (string) $value)
				);
			}

			if (abs($value) > self::FLOAT_SAFE_INTEGER_MAX) {
				throw new InvalidArgumentException(
					sprintf("Field '%s' cannot safely convert float '%s' to int.", $field->getName(), (string) $value)
				);
			}

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

		if (! self::isWithinPlatformIntRange($trimmed)) {
			throw new InvalidArgumentException(
				sprintf("Field '%s' cannot convert out-of-range integer '%s'.", $field->getName(), $value)
			);
		}

		return (int) $trimmed;
	}

	private static function isWithinPlatformIntRange(string $value): bool
	{
		$negative = str_starts_with($value, '-');
		$unsigned = ltrim($value, '+-');
		$normalized = ltrim($unsigned, '0');
		$normalized = $normalized === '' ? '0' : $normalized;

		$limit = $negative
			? ltrim((string) PHP_INT_MIN, '-')
			: (string) PHP_INT_MAX;

		if (strlen($normalized) !== strlen($limit)) {
			return strlen($normalized) < strlen($limit);
		}

		return strcmp($normalized, $limit) <= 0;
	}
}
