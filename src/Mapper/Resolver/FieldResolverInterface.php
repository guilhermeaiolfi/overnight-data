<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Resolver;

use ON\Data\Mapper\FieldContext;
use ON\Data\Mapper\MappingContext;

interface FieldResolverInterface
{
	public function resolve(
		MappingContext $mapping,
		string $path,
		string|int $fieldName,
		mixed $value,
		mixed $extra = null,
	): ?FieldContext;
}
