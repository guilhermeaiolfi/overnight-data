<?php

declare(strict_types=1);

namespace ON\Data\Mapper;

use ON\Data\Mapper\Representation\RepresentationInterface;

interface FieldTypeCodecInterface
{
	/**
	 * @return class-string<FieldTypeInterface>
	 */
	public static function getFieldType(): string;

	/**
	 * @return class-string<RepresentationInterface>
	 */
	public static function getRepresentation(): string;

	public static function toPhp(
		mixed $value,
		FieldContext $field,
	): mixed;

	public static function fromPhp(
		mixed $value,
		FieldContext $field,
	): mixed;
}
