<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use ON\Data\Mapper\FieldTypeInterface;
use ON\Data\Mapper\Resolution\LeafNodeResolutionInterface;

final class CustomFieldType implements FieldTypeInterface
{
	public static function getNames(): array
	{
		return ['custom'];
	}

	public static function getStorageType(): string
	{
		return 'custom';
	}

	public static function toPhp(mixed $value, LeafNodeResolutionInterface $field): mixed
	{
		return strtoupper((string) $value);
	}

	public static function fromPhp(mixed $value, LeafNodeResolutionInterface $field): mixed
	{
		return strtolower((string) $value);
	}
}
