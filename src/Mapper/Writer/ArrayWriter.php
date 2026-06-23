<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Writer;

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

	public function createTarget(
		MappingNode $node,
	): array {
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
