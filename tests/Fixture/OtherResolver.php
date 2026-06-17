<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use ON\Data\Mapper\FieldContext;
use ON\Data\Mapper\MappingContext;
use ON\Data\Mapper\Resolver\FieldResolverInterface;

final class OtherResolver implements FieldResolverInterface
{
	public function resolve(
		MappingContext $mapping,
		string $path,
		string|int $fieldName,
		mixed $value,
		mixed $extra = null,
	): ?FieldContext {
		return FieldContext::named((string) $fieldName, 'string');
	}
}
