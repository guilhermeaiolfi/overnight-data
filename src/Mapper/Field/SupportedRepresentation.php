<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Field;

use ON\Data\Mapper\Exception\UnsupportedConversionException;
use ON\Data\Mapper\Representation\PhpRepresentation;
use ON\Data\Mapper\Representation\StorageRepresentation;
use ON\Data\Mapper\Representation\WireRepresentation;

final class SupportedRepresentation
{
	private function __construct()
	{
	}

	/**
	 * @param class-string $representation
	 */
	public static function assert(string $representation, string $fieldType): void
	{
		if (
			$representation !== PhpRepresentation::class
			&& $representation !== StorageRepresentation::class
			&& $representation !== WireRepresentation::class
		) {
			throw new UnsupportedConversionException(
				sprintf("Representation '%s' is not supported by %s.", $representation, $fieldType)
			);
		}
	}
}
