<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Field;

use ON\Data\Mapper\FieldContext;
use ON\Data\Mapper\FieldTypeInterface;

final class PassthroughFieldType implements FieldTypeInterface
{
	public static function getNames(): array
	{
		return ['text'];
	}

	public static function getStorageType(): string
	{
		return 'text';
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
