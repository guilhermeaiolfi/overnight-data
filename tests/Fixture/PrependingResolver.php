<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use ON\Data\Mapper\FieldContext;
use ON\Data\Mapper\MappingContext;
use ON\Data\Mapper\Resolver\FieldResolverInterface;

final class PrependingResolver implements FieldResolverInterface
{
	public function __construct()
	{
		ComponentTestState::recordConstruction(self::class);
	}

	public function resolve(
		MappingContext $mapping,
		string $path,
		string|int $fieldName,
		mixed $value,
		mixed $extra = null,
	): ?FieldContext {
		ComponentTestState::recordRuntime(self::class, $path);

		if ($fieldName !== 'id') {
			return null;
		}

		return FieldContext::named('id', 'string');
	}
}
