<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Field;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use ON\Data\Mapper\FieldContext;
use ON\Data\Mapper\FieldTypeInterface;

final class DateFieldType implements FieldTypeInterface
{
	private const STORAGE_FORMAT = 'Y-m-d';

	public static function getNames(): array
	{
		return ['date'];
	}

	public static function getStorageType(): string
	{
		return 'date';
	}

	public static function toPhp(mixed $value, FieldContext $field): mixed
	{
		if ($value instanceof DateTimeInterface) {
			return self::normalizeDateObject($value);
		}

		if (! is_string($value)) {
			throw new InvalidArgumentException(
				sprintf("Field '%s' expects a date string in '%s' format or a %s instance.", $field->getName(), self::STORAGE_FORMAT, DateTimeInterface::class),
			);
		}

		return self::parseDateString($value, self::STORAGE_FORMAT, $field);
	}

	public static function fromPhp(mixed $value, FieldContext $field): mixed
	{
		return self::normalizeDateObject($value)->format(self::STORAGE_FORMAT);
	}

	public static function parseDateString(string $value, string $format, FieldContext $field): DateTimeImmutable
	{
		$normalized = trim($value);
		$date = DateTimeImmutable::createFromFormat('!' . $format, $normalized);
		$errors = DateTimeImmutable::getLastErrors();

		if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
			throw new InvalidArgumentException(
				sprintf("Field '%s' expects a date in '%s' format; received '%s'.", $field->getName(), $format, $value),
			);
		}

		return $date;
	}

	public static function normalizeDateObject(mixed $value): DateTimeImmutable
	{
		if (! $value instanceof DateTimeInterface) {
			throw new InvalidArgumentException(
				sprintf('Date value must implement %s.', DateTimeInterface::class),
			);
		}

		return DateTimeImmutable::createFromInterface($value)->setTime(0, 0, 0);
	}
}
