<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use ON\Data\Mapper\FieldContext;
use ON\Data\Mapper\FieldTypeInterface;
use ON\Data\Mapper\Representation\PhpRepresentation;

final class CustomFieldType implements FieldTypeInterface
{
	public static function storageType(): string
	{
		return 'custom';
	}

	public static function toPhp(string $from, mixed $value, FieldContext $field): mixed
	{
		return strtoupper((string) $value);
	}

	public static function fromPhp(string $to, mixed $value, FieldContext $field): mixed
	{
		if ($to === PhpRepresentation::class) {
			return strtoupper((string) $value);
		}

		return strtolower((string) $value);
	}
}
