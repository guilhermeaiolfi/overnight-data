<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use ON\Data\Mapper\Mapper\MapperInterface;
use ON\Data\Mapper\MapperManager;
use ON\Data\Mapper\MappingContext;
use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\Writer\WriterInterface;

final class MultiRoleComponent implements MapperInterface, WriterInterface
{
	public static function canMap(
		mixed $source,
		MappingContext $context,
	): bool {
		return false;
	}

	public function map(
		MappingNode $node,
		MapperManager $mapperManager,
	): mixed {
		return null;
	}

	public static function canWrite(
		mixed $target,
		MappingContext $context,
	): bool {
		return false;
	}

	public function prepare(
		mixed $target,
		MappingContext $context,
	): mixed {
		return $target;
	}

	public function write(
		mixed $target,
		MappingNode $node,
		mixed $value,
	): mixed {
		return $target;
	}

	public function finish(
		mixed $target,
		MappingContext $context,
	): mixed {
		return $target;
	}
}
