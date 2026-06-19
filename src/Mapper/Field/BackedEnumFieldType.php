<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Field;

use BackedEnum;
use InvalidArgumentException;
use ON\Data\Mapper\FieldTypeInterface;
use ON\Data\Mapper\Resolution\LeafNodeResolutionInterface;

final class BackedEnumFieldType implements FieldTypeInterface
{
	public static function getNames(): array
	{
		return ['backed-enum'];
	}

	public static function getStorageType(): string
	{
		return 'string';
	}

	public static function toPhp(mixed $value, LeafNodeResolutionInterface $field): mixed
	{
		if ($value instanceof BackedEnum) {
			return $value;
		}

		$enum = self::resolveEnumClass($field);

		return $enum::from($value);
	}

	public static function fromPhp(mixed $value, LeafNodeResolutionInterface $field): mixed
	{
		if (! $value instanceof BackedEnum) {
			throw new InvalidArgumentException(
				sprintf("Field '%s' expects a backed enum instance.", $field->getName()),
			);
		}

		return $value->value;
	}

	/**
	 * @return class-string<BackedEnum>
	 */
	private static function resolveEnumClass(LeafNodeResolutionInterface $field): string
	{
		$type = $field->getType();
		if (! enum_exists($type) || ! is_a($type, BackedEnum::class, true)) {
			throw new InvalidArgumentException(
				sprintf("Field '%s' must declare a backed enum class; received '%s'.", $field->getName(), $type),
			);
		}

		/** @var class-string<BackedEnum> $type */
		return $type;
	}
}
