<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use ON\Data\Mapper\FieldContext;
use ON\Data\Mapper\FieldTypeInterface;

final class PartiallyInvalidNamesFieldType implements FieldTypeInterface
{
	public static function getNames(): array
	{
		return ['valid-name', ''];
	}

	public static function getStorageType(): string
	{
		return 'partial-invalid';
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
