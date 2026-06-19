<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use ON\Data\Mapper\FieldTypeInterface;
use ON\Data\Mapper\Resolution\LeafNodeResolutionInterface;

final class UpperMoneyFieldType implements FieldTypeInterface
{
	public static function getNames(): array
	{
		return ['Money'];
	}

	public static function getStorageType(): string
	{
		return 'money-upper';
	}

	public static function toPhp(mixed $value, LeafNodeResolutionInterface $field): mixed
	{
		return 'upper<' . (string) $value . '>';
	}

	public static function fromPhp(mixed $value, LeafNodeResolutionInterface $field): mixed
	{
		return 'upper<' . (string) $value . '>';
	}
}
