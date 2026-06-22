<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use ON\Data\Mapper\Mapper\MapperInterface;
use ON\Data\Mapper\MappingContext;
use ON\Data\Mapper\MappingRuntime;

final class OtherArrayMapper implements MapperInterface
{
	public static function canMap(
		mixed $source,
		MappingContext $context,
	): bool {
		return is_array($source);
	}

	public function map(MappingRuntime $runtime): mixed
	{
		foreach ($runtime->getSource() as $name => $value) {
			$runtime->write(name: $name, value: $value);
		}

		return $runtime->getResult();
	}
}
