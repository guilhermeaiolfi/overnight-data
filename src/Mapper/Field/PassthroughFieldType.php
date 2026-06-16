<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Field;

use ON\Data\Mapper\FieldContext;

final class PassthroughFieldType extends AbstractPrimitiveFieldType
{
	public static function storageType(): string
	{
		return 'text';
	}

	protected static function normalizeToPhp(mixed $value, FieldContext $field): mixed
	{
		return $value;
	}

	protected static function normalizeFromPhp(mixed $value, FieldContext $field): mixed
	{
		return $value;
	}
}
