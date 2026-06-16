<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Representation;

use ON\Data\Mapper\FieldContext;

final class StorageRepresentation implements RepresentationInterface
{
	public function toPhp(mixed $value, FieldContext $field): mixed
	{
		return $value;
	}

	public function fromPhp(mixed $value, FieldContext $field): mixed
	{
		return $value;
	}
}
