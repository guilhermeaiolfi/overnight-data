<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use ON\Data\Mapper\Mapper\MapperInterface;
use ON\Data\Mapper\MappingContext;
use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\MappingRuntime;
use ON\Data\Mapper\Writer\WriterInterface;

final class MultiRoleComponent implements MapperInterface, WriterInterface
{
	public static function canMap(
		mixed $source,
		MappingContext $context,
	): bool {
		return false;
	}

	public function map(MappingRuntime $runtime): mixed
	{
		return null;
	}

	public static function canWrite(
		mixed $target,
		MappingContext $context,
	): bool {
		return false;
	}

	public function createTarget(MappingNode $node): mixed
	{
		return $node->getTarget();
	}

	public function write(
		mixed $target,
		string|int $name,
		mixed $value,
		MappingNode $node,
	): mixed {
		return $target;
	}
}
