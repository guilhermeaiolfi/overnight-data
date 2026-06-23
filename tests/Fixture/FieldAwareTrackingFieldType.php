<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use ON\Data\Mapper\FieldTypeInterface;
use ON\Data\Mapper\Resolution\LeafNodeResolutionInterface;

final class FieldAwareTrackingFieldType implements FieldTypeInterface
{
	public static function getNames(): array
	{
		return ['field-aware-tracked'];
	}

	public static function getStorageType(): string
	{
		return 'field-aware-tracked';
	}

	public static function toPhp(mixed $value, LeafNodeResolutionInterface $field): mixed
	{
		return $field->getName() . '<' . (string) $value . '>';
	}

	public static function fromPhp(mixed $value, LeafNodeResolutionInterface $field): mixed
	{
		return $field->getName() . '<' . (string) $value . '>';
	}
}
