<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use ON\Data\Mapper\FieldContext;
use ON\Data\Mapper\FieldTypeCodecInterface;

final class TrackingCacheCodec implements FieldTypeCodecInterface
{
	public static function getFieldType(): string
	{
		return TrackingCustomFieldType::class;
	}

	public static function getRepresentation(): string
	{
		return CacheRepresentation::class;
	}

	public static function toPhp(mixed $value, FieldContext $field): mixed
	{
		TrackingCustomFieldType::record('cacheCodec:toPhp');

		return 'php<' . (string) $value . '>';
	}

	public static function fromPhp(mixed $value, FieldContext $field): mixed
	{
		TrackingCustomFieldType::record('cacheCodec:fromPhp');

		return 'cache<' . (string) $value . '>';
	}
}
