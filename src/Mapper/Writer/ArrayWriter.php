<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Writer;

use ON\Data\Mapper\MappingContext;

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
		string|int $name,
		mixed $value,
		MappingContext $context,
		mixed $walkerArguments = null,
	): array {
		$target[$name] = $value;

		return $target;
	}

	public function finish(
		mixed $target,
		MappingContext $context,
	): array {
		return $target;
	}
}
