<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Writer;

use LogicException;
use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\MappingOptions;

final class ArrayWriter implements WriterInterface
{
	public static function canWrite(
		mixed $target,
		MappingOptions $options,
	): bool {
		return is_array($target);
	}

	public function createState(
		MappingNode $node,
	): WriterStateInterface {
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
		if (! $state instanceof ArrayWriterState) {
			throw new LogicException('ArrayWriter requires ArrayWriterState.');
		}

		$state->items[$name] = $value;
	}

	public function getResult(
		WriterStateInterface $state,
		MappingNode $node,
	): array {
		if (! $state instanceof ArrayWriterState) {
			throw new LogicException('ArrayWriter requires ArrayWriterState.');
		}

		return $state->items;
	}
}
