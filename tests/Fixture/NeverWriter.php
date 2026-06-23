<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\MappingOptions;
use ON\Data\Mapper\Writer\WriterInterface;

final class NeverWriter implements WriterInterface
{
	public function __construct()
	{
		ComponentTestState::recordConstruction(self::class);
	}

	public static function canWrite(
		mixed $target,
		MappingOptions $options,
	): bool {
		ComponentTestState::recordSelection(self::class);

		return false;
	}

	public function createTarget(MappingNode $node): mixed
	{
		ComponentTestState::recordRuntime(self::class);

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
