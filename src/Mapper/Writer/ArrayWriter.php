<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Writer;

use ON\Data\Mapper\MappingContext;
use ON\Data\Mapper\MappingNode;

final class ArrayWriter implements WriterInterface
{
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
