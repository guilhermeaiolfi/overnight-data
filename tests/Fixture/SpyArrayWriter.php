<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use LogicException;
use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\MappingOptions;
use ON\Data\Mapper\Writer\ArrayWriterState;
use ON\Data\Mapper\Writer\WriterInterface;
use ON\Data\Mapper\Writer\WriterStateInterface;

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

	public function createState(MappingNode $node): WriterStateInterface
	{
		ComponentTestState::recordRuntime(self::class);
		$state = new ArrayWriterState();
		$target = $node->getTarget();
		$state->items = is_array($target) ? $target : [];

		return $state;
	}

	public function write(
		WriterStateInterface $state,
		string|int $name,
		mixed $value,
		MappingNode $node,
	): void {
		$state instanceof ArrayWriterState || throw new LogicException();
		$state->items[$name] = $value;
	}

	public function getResult(
		WriterStateInterface $state,
		MappingNode $node,
	): array {
		$state instanceof ArrayWriterState || throw new LogicException();

		return $state->items;
	}
}
