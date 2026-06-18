<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use ON\Data\Mapper\FieldContext;
use ON\Data\Mapper\FieldTypeCodecInterface;

final class TrackingApiCodec implements FieldTypeCodecInterface
{
	public static function getFieldType(): string
	{
		return TrackingCustomFieldType::class;
	}

	public static function getRepresentation(): string
	{
		return ApiRepresentation::class;
	}

	public static function toPhp(mixed $value, FieldContext $field): mixed
	{
		TrackingCustomFieldType::record('apiCodec:toPhp');

		return 'php-api<' . (string) $value . '>';
	}

	public static function fromPhp(mixed $value, FieldContext $field): mixed
	{
		TrackingCustomFieldType::record('apiCodec:fromPhp');

		return 'api<' . (string) $value . '>';
	}
}
