<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use ON\Data\Mapper\FieldTypeInterface;
use ON\Data\Mapper\Resolution\LeafNodeResolutionInterface;

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

	public static function toPhp(mixed $value, LeafNodeResolutionInterface $field): mixed
	{
		return $value;
	}

	public static function fromPhp(mixed $value, LeafNodeResolutionInterface $field): mixed
	{
		return $value;
	}
}
