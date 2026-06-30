<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use LogicException;
use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\MappingOptions;
use ON\Data\Mapper\Writer\ArrayWriterState;
use ON\Data\Mapper\Writer\ObjectWriterState;
use ON\Data\Mapper\Writer\WriterInterface;
use ON\Data\Mapper\Writer\WriterStateInterface;
use stdClass;

final class RuntimeInvariantWriter implements WriterInterface
{
	/**
	 * @var list<array{
	 *     path: string,
	 *     state: mixed,
	 *     parentTarget: mixed,
	 * }>
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
		return is_array($target) || $target instanceof stdClass || $target === stdClass::class;
	}

	public function createState(MappingNode $node): WriterStateInterface
	{
		$target = $node->getTarget();
		if (is_array($target)) {
			$state = new ArrayWriterState();
			$state->items = $target;

			return $state;
		}

		$state = new ObjectWriterState();
		$state->target = $target instanceof stdClass ? clone $target : new stdClass();

		return $state;
	}

	public function write(
		WriterStateInterface $state,
		string|int $name,
		mixed $value,
		MappingNode $node,
	): void {
		self::$writes[] = [
			'path' => $node->getPath(),
			'state' => $this->normalizeState($state),
			'parentTarget' => $this->normalize($node->getParentTarget()),
		];

		if ($state instanceof ArrayWriterState) {
			$state->items[$name] = $value;

			return;
		}

		if ($state instanceof ObjectWriterState && $state->target instanceof stdClass) {
			$state->target->{(string) $name} = $value;

			return;
		}

		throw new LogicException();
	}

	public function getResult(
		WriterStateInterface $state,
		MappingNode $node,
	): array|stdClass {
		return match (true) {
			$state instanceof ArrayWriterState => $state->items,
			$state instanceof ObjectWriterState && $state->target instanceof stdClass => $state->target,
			default => throw new LogicException(),
		};
	}

	private function normalizeState(WriterStateInterface $state): mixed
	{
		return match (true) {
			$state instanceof ArrayWriterState => $this->normalize($state->items),
			$state instanceof ObjectWriterState => $this->normalize($state->target),
			default => null,
		};
	}

	private function normalize(mixed $value): mixed
	{
		if (is_array($value)) {
			$result = [];

			foreach ($value as $key => $item) {
				$result[$key] = $this->normalize($item);
			}

			return $result;
		}

		if ($value instanceof stdClass) {
			$result = [];

			foreach (get_object_vars($value) as $key => $item) {
				$result[$key] = $this->normalize($item);
			}

			return $result;
		}

		return $value;
	}
}
