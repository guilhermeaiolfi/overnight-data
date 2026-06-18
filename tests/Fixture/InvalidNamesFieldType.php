<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use ON\Data\Mapper\FieldContext;
use ON\Data\Mapper\FieldTypeInterface;

final class InvalidNamesFieldType implements FieldTypeInterface
{
	public static function getNames(): array
	{
		return [''];
	}

	public static function getStorageType(): string
	{
		return 'invalid';
	}

	public static function toPhp(mixed $value, FieldContext $field): mixed
	{
		return $value;
	}

	public static function fromPhp(mixed $value, FieldContext $field): mixed
	{
		return $value;
	}
}
