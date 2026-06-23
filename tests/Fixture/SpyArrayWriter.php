<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\MappingOptions;
use ON\Data\Mapper\Writer\WriterInterface;

final class SpyArrayWriter implements WriterInterface
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

		return is_array($target);
	}

	public function createTarget(MappingNode $node): array
	{
		ComponentTestState::recordRuntime(self::class);
		$target = $node->getTarget();

		return is_array($target) ? $target : [];
	}

	public function write(
		mixed $target,
		string|int $name,
		mixed $value,
		MappingNode $node,
	): array {
		$target[$name] = $value;

		return $target;
	}
}
