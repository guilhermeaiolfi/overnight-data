<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use ON\Data\Mapper\Mapper\MapperInterface;
use ON\Data\Mapper\MappingContext;
use ON\Data\Mapper\MappingOptions;

final class CustomMapper implements MapperInterface
{
	public static function canMap(
		mixed $source,
		MappingOptions $options,
	): bool {
		return is_array($source);
	}

	public function map(MappingContext $context): mixed
	{
		foreach ((array) $context->getSource() as $name => $value) {
			$context->write(
				name: (string) $name,
				value: $value,
			);
		}

		return $context->getResult();
	}
}
