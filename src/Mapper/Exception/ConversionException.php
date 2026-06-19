<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Exception;

use ON\Data\Mapper\Resolution\LeafNodeResolutionInterface;
use Throwable;

class ConversionException extends MappingException
{
	/**
	 * @param class-string $from
	 * @param class-string $to
	 */
	public static function forField(
		LeafNodeResolutionInterface $field,
		string $from,
		string $to,
		Throwable $previous,
	): self {
		return new self(
			sprintf(
				"Failed converting field '%s' of type '%s' from %s to %s: %s",
				$field->getName(),
				$field->getType(),
				$from,
				$to,
				$previous->getMessage(),
			),
			0,
			$previous,
		);
	}
}
