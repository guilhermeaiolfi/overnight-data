<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use ON\Data\Mapper\Mapper\MapperInterface;
use ON\Data\Mapper\MappingBranch;
use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\MappingOptions;
use ON\Data\Mapper\Writer\ArrayWriterState;
use ON\Data\Mapper\Writer\WriterInterface;
use ON\Data\Mapper\Writer\WriterStateInterface;

final class MultiRoleComponent implements MapperInterface, WriterInterface
{
	public static function canMap(
		mixed $source,
		MappingOptions $options,
	): bool {
		return false;
	}

	public function map(MappingBranch $context): mixed
	{
		return null;
	}

	public static function canWrite(
		mixed $target,
		MappingOptions $options,
	): bool {
		return false;
	}

	public function createState(MappingNode $node): WriterStateInterface
	{
		return new ArrayWriterState();
	}

	public function write(
		WriterStateInterface $state,
		string|int $name,
		mixed $value,
		MappingNode $node,
	): void {
	}

	public function getResult(
		WriterStateInterface $state,
		MappingNode $node,
	): mixed {
		return null;
	}
}
