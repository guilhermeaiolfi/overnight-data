<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use ON\Data\Mapper\Mapper\Mapper;
use ON\Data\Mapper\MappingContext;
use ON\Data\Mapper\MappingNode;

final class OtherArrayMapper extends Mapper
{
	public static function canMap(
		mixed $source,
		MappingContext $context,
	): bool {
		return is_array($source);
	}

	protected function getNodes(
		MappingNode $node,
	): iterable {
		foreach ($node->getValue() as $name => $value) {
			yield $node->child($name, $value);
		}
	}
}
