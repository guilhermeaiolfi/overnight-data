<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use ON\Data\Mapper\FieldContext;
use ON\Data\Mapper\FieldTypeInterface;

final class LowerMoneyFieldType implements FieldTypeInterface
{
	public static function getNames(): array
	{
		return ['money'];
	}

	public static function getStorageType(): string
	{
		return 'money-lower';
	}

	public static function toPhp(mixed $value, FieldContext $field): mixed
	{
		return 'lower<' . (string) $value . '>';
	}

	public static function fromPhp(mixed $value, FieldContext $field): mixed
	{
		return 'lower<' . (string) $value . '>';
	}
}
