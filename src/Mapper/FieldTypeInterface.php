<?php

declare(strict_types=1);

namespace ON\Data\Mapper;

use ON\Data\Mapper\Representation\RepresentationInterface;

interface FieldTypeInterface
{
	public static function storageType(): string;

	/**
	 * @param class-string<RepresentationInterface> $from
	 */
	public static function toPhp(
		string $from,
		mixed $value,
		FieldContext $field,
	): mixed;

	/**
	 * @param class-string<RepresentationInterface> $to
	 */
	public static function fromPhp(
		string $to,
		mixed $value,
		FieldContext $field,
	): mixed;
}
