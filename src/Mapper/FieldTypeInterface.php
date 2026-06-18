<?php

declare(strict_types=1);

namespace ON\Data\Mapper;

interface FieldTypeInterface
{
	/**
	 * @return non-empty-list<non-empty-string>
	 */
	public static function getNames(): array;

	public static function getStorageType(): string;

	public static function toPhp(
		mixed $value,
		FieldContext $field,
	): mixed;

	public static function fromPhp(
		mixed $value,
		FieldContext $field,
	): mixed;
}
