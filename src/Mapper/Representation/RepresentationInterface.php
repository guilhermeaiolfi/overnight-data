<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Representation;

use ON\Data\Mapper\FieldContext;

interface RepresentationInterface
{
	public function toPhp(
		mixed $value,
		FieldContext $field,
	): mixed;

	public function fromPhp(
		mixed $value,
		FieldContext $field,
	): mixed;
}
