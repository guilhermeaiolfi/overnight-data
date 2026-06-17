<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use ON\Data\Mapper\Exception\UnsupportedConversionException;
use ON\Data\Mapper\FieldContext;
use ON\Data\Mapper\FieldTypeInterface;
use ON\Data\Mapper\Representation\PhpRepresentation;
use ON\Data\Mapper\Representation\StorageRepresentation;
use ON\Data\Mapper\Representation\WireRepresentation;

final class TrackingCustomFieldType implements FieldTypeInterface
{
	/**
	 * @var list<string>
	 */
	private static array $calls = [];

	public static function reset(): void
	{
		self::$calls = [];
	}

	/**
	 * @return list<string>
	 */
	public static function calls(): array
	{
		return self::$calls;
	}

	public static function storageType(): string
	{
		return 'tracked';
	}

	public static function toPhp(string $from, mixed $value, FieldContext $field): mixed
	{
		self::$calls[] = 'toPhp:' . $from;

		return match ($from) {
			CacheRepresentation::class => 'php<' . (string) $value . '>',
			StorageRepresentation::class => 'php-storage<' . (string) $value . '>',
			WireRepresentation::class => 'php-wire<' . (string) $value . '>',
			default => throw new UnsupportedConversionException(
				sprintf("Representation '%s' is not supported by %s.", $from, static::class)
			),
		};
	}

	public static function fromPhp(string $to, mixed $value, FieldContext $field): mixed
	{
		self::$calls[] = 'fromPhp:' . $to;

		return match ($to) {
			CacheRepresentation::class => 'cache<' . (string) $value . '>',
			StorageRepresentation::class => 'storage<' . (string) $value . '>',
			WireRepresentation::class => 'wire<' . (string) $value . '>',
			PhpRepresentation::class => 'php-out<' . (string) $value . '>',
			default => throw new UnsupportedConversionException(
				sprintf("Representation '%s' is not supported by %s.", $to, static::class)
			),
		};
	}
}
