<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Field;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use ON\Data\Mapper\FieldContext;
use ON\Data\Mapper\FieldTypeCodecInterface;
use ON\Data\Mapper\Representation\WireRepresentation;
use Throwable;

final class DateTimeWireCodec implements FieldTypeCodecInterface
{
	public static function getFieldType(): string
	{
		return DateTimeFieldType::class;
	}

	public static function getRepresentation(): string
	{
		return WireRepresentation::class;
	}

	public static function toPhp(mixed $value, FieldContext $field): mixed
	{
		if ($value instanceof DateTimeInterface) {
			return DateTimeFieldType::normalizeDateTimeObject($value);
		}

		if (! is_string($value) || trim($value) === '') {
			throw new InvalidArgumentException(
				sprintf("Field '%s' expects an ISO-8601 wire datetime string.", $field->getName()),
			);
		}

		try {
			return new DateTimeImmutable(trim($value));
		} catch (Throwable $exception) {
			throw new InvalidArgumentException(
				sprintf("Field '%s' expects an ISO-8601 wire datetime string; received '%s'.", $field->getName(), $value),
				0,
				$exception,
			);
		}
	}

	public static function fromPhp(mixed $value, FieldContext $field): mixed
	{
		return DateTimeFieldType::normalizeDateTimeObject($value)->format(DateTimeInterface::ATOM);
	}
}
