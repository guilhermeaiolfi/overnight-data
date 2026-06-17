<?php

declare(strict_types=1);

namespace ON\Data\Mapper;

interface FieldContextResolverInterface
{
	public function resolve(
		mixed $source,
		MappingContext $context,
	): ?FieldContext;
}
