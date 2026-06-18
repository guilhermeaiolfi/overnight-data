<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use ON\Data\Mapper\FieldContext;
use ON\Data\Mapper\FieldTypeInterface;

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

	public static function record(string $call): void
	{
		self::$calls[] = $call;
	}

	public static function getNames(): array
	{
		return ['tracked'];
	}

	public static function getStorageType(): string
	{
		return 'tracked';
	}

	public static function toPhp(mixed $value, FieldContext $field): mixed
	{
		self::$calls[] = 'fieldType:toPhp';

		return 'field-php<' . (string) $value . '>';
	}

	public static function fromPhp(mixed $value, FieldContext $field): mixed
	{
		self::$calls[] = 'fieldType:fromPhp';

		return 'field-out<' . (string) $value . '>';
	}
}
