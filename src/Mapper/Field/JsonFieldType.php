<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Field;

use JsonException;
use ON\Data\Mapper\FieldTypeInterface;
use ON\Data\Mapper\Resolution\LeafNodeResolutionInterface;

final class JsonFieldType implements FieldTypeInterface
{
	public static function getNames(): array
	{
		return ['json'];
	}

	public static function getStorageType(): string
	{
		return 'json';
	}

	public static function toPhp(mixed $value, LeafNodeResolutionInterface $field): mixed
	{
		if ($value === null || ! is_string($value)) {
			return $value;
		}

		return self::decodeJson($value);
	}

	public static function fromPhp(mixed $value, LeafNodeResolutionInterface $field): mixed
	{
		if ($value === null || is_string($value)) {
			return $value;
		}

		return self::encodeJson($value);
	}

	private static function decodeJson(string $value): mixed
	{
		try {
			return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
		} catch (JsonException $exception) {
			throw $exception;
		}
	}

	private static function encodeJson(mixed $value): string
	{
		try {
			return json_encode($value, JSON_THROW_ON_ERROR);
		} catch (JsonException $exception) {
			throw $exception;
		}
	}
}
