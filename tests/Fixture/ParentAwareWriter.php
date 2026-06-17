<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use ON\Data\Mapper\MappingContext;
use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\Writer\WriterInterface;

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
		MappingContext $context,
	): bool {
		return is_array($target);
	}

	public function prepare(
		mixed $target,
		MappingContext $context,
	): array {
		return is_array($target) ? $target : [];
	}

	public function write(
		mixed $target,
		MappingNode $node,
		mixed $value,
	): array {
		self::$writes[] = [
			'path' => $node->getPath(),
			'hasParentSource' => $node->getParentSource() !== null,
			'hasParentTarget' => $node->getParentTarget() !== null,
			'valueType' => is_object($value) ? $value::class : get_debug_type($value),
		];

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
