<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use ON\Data\Mapper\FieldTypeInterface;
use ON\Data\Mapper\Resolution\LeafNodeResolutionInterface;

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

	public static function toPhp(mixed $value, LeafNodeResolutionInterface $field): mixed
	{
		return 'lower<' . (string) $value . '>';
	}

	public static function fromPhp(mixed $value, LeafNodeResolutionInterface $field): mixed
	{
		return 'lower<' . (string) $value . '>';
	}
}
