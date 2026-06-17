<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use ON\Data\Mapper\MappingContext;
use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\Writer\WriterInterface;

final class SpyArrayWriter implements WriterInterface
{
	public function __construct()
	{
		ComponentTestState::recordConstruction(self::class);
	}

	public static function canWrite(
		mixed $target,
		MappingContext $context,
	): bool {
		ComponentTestState::recordSelection(self::class);

		return is_array($target);
	}

	public function prepare(
		mixed $target,
		MappingContext $context,
	): array {
		ComponentTestState::recordRuntime(self::class);

		return is_array($target) ? $target : [];
	}

	public function write(
		mixed $target,
		MappingNode $node,
		mixed $value,
	): array {
		$target[$node->getName()] = $value;

		return $target;
	}

	public function finish(
		mixed $target,
		MappingContext $context,
	): array {
		return $target;
	}
}
