<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Field;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use ON\Data\Mapper\FieldContext;
use ON\Data\Mapper\FieldTypeInterface;

final class DateTimeFieldType implements FieldTypeInterface
{
	private const STORAGE_FORMAT = 'Y-m-d H:i:s';

	public static function getNames(): array
	{
		return ['datetime', 'timestamp'];
	}

	public static function getStorageType(): string
	{
		return 'datetime';
	}

	public static function toPhp(mixed $value, FieldContext $field): mixed
	{
		if ($value instanceof DateTimeInterface) {
			return self::normalizeDateTimeObject($value);
		}

		if (! is_string($value) || trim($value) === '') {
			throw new InvalidArgumentException(
				sprintf("Field '%s' expects a datetime string in '%s' format or a %s instance.", $field->getName(), self::STORAGE_FORMAT, DateTimeInterface::class),
			);
		}

		return self::parseDateTimeString($value, self::STORAGE_FORMAT, $field);
	}

	public static function fromPhp(mixed $value, FieldContext $field): mixed
	{
		return self::normalizeDateTimeObject($value)->format(self::STORAGE_FORMAT);
	}

	public static function parseDateTimeString(string $value, string $format, FieldContext $field): DateTimeImmutable
	{
		$normalized = trim($value);
		$dateTime = DateTimeImmutable::createFromFormat('!' . $format, $normalized);
		$errors = DateTimeImmutable::getLastErrors();

		if ($dateTime === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
			throw new InvalidArgumentException(
				sprintf("Field '%s' expects a datetime in '%s' format; received '%s'.", $field->getName(), $format, $value),
			);
		}

		return $dateTime;
	}

	public static function normalizeDateTimeObject(mixed $value): DateTimeImmutable
	{
		if (! $value instanceof DateTimeInterface) {
			throw new InvalidArgumentException(
				sprintf('Datetime value must implement %s.', DateTimeInterface::class),
			);
		}

		return DateTimeImmutable::createFromInterface($value);
	}
}
