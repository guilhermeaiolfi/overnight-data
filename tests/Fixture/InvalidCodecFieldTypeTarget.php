<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use ON\Data\Mapper\FieldContext;
use ON\Data\Mapper\FieldTypeCodecInterface;
use ON\Data\Mapper\Representation\WireRepresentation;
use stdClass;

final class InvalidCodecFieldTypeTarget implements FieldTypeCodecInterface
{
	public static function getFieldType(): string
	{
		return stdClass::class;
	}

	public static function getRepresentation(): string
	{
		return WireRepresentation::class;
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
