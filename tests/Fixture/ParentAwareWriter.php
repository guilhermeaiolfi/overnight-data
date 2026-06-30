<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use LogicException;
use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\MappingOptions;
use ON\Data\Mapper\Writer\ArrayWriterState;
use ON\Data\Mapper\Writer\WriterInterface;
use ON\Data\Mapper\Writer\WriterStateInterface;

final class ParentAwareWriter implements WriterInterface
{
	/**
	 * @var list<array{path: string, hasParentSource: bool, hasParentTarget: bool, valueType: string}>
	 */
	public static array $writes = [];

	public static function reset(): void
	{
		self::$writes = [];
	}

	public static function canWrite(
		mixed $target,
		MappingOptions $options,
	): bool {
		return is_array($target);
	}

	public function createState(MappingNode $node): WriterStateInterface
	{
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
		self::$writes[] = [
			'path' => $node->getPath(),
			'hasParentSource' => $node->getParentSource() !== null,
			'hasParentTarget' => $node->getParentTarget() !== null,
			'valueType' => is_object($value) ? $value::class : get_debug_type($value),
		];

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
