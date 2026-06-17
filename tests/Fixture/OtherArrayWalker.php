<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use ON\Data\Mapper\MappingContext;
use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\Walker\Walker;

final class OtherArrayWalker extends Walker
{
	public static function canWalk(
		mixed $source,
		MappingContext $context,
	): bool {
		return is_array($source);
	}

	protected function getNodes(
		mixed $source,
		MappingContext $context,
	): iterable {
		foreach ($source as $name => $value) {
			yield new MappingNode($name, $value, $context);
		}
	}
}
