<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use ON\Data\Mapper\MappingContext;
use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\Writer\WriterInterface;
use stdClass;

final class RuntimeInvariantWriter implements WriterInterface
{
	/**
	 * @var list<array{
	 *     path: string,
	 *     target: mixed,
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
		MappingContext $context,
	): bool {
		return is_array($target) || $target instanceof stdClass || $target === stdClass::class;
	}

	public function prepare(
		mixed $target,
		MappingContext $context,
	): array|stdClass {
		if (is_array($target)) {
			return $target;
		}

		return $target instanceof stdClass ? clone $target : new stdClass();
	}

	public function write(
		mixed $target,
		MappingNode $node,
		mixed $value,
	): array|stdClass {
		self::$writes[] = [
			'path' => $node->getPath(),
			'target' => $this->normalize($target),
			'parentTarget' => $this->normalize($node->getParentTarget()),
		];

		if (is_array($target)) {
			$target[$node->getName()] = $value;

			return $target;
		}

		$target->{(string) $node->getName()} = $value;

		return $target;
	}

	public function finish(
		mixed $target,
		MappingContext $context,
	): array|stdClass {
		return $target;
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
