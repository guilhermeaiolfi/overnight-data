<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use ON\Data\Mapper\FieldTypeCodecInterface;
use ON\Data\Mapper\Representation\WireRepresentation;
use ON\Data\Mapper\Resolution\LeafNodeResolutionInterface;

final class ReplacementTrackingWireCodec implements FieldTypeCodecInterface
{
	public static function getFieldType(): string
	{
		return TrackingCustomFieldType::class;
	}

	public static function getRepresentation(): string
	{
		return WireRepresentation::class;
	}

	public static function toPhp(mixed $value, LeafNodeResolutionInterface $field): mixed
	{
		TrackingCustomFieldType::record('replacementWireCodec:toPhp');

		return 'replacement-php-wire<' . (string) $value . '>';
	}

	public static function fromPhp(mixed $value, LeafNodeResolutionInterface $field): mixed
	{
		TrackingCustomFieldType::record('replacementWireCodec:fromPhp');

		return 'replacement-wire<' . (string) $value . '>';
	}
}
