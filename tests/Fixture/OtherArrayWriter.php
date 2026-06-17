<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use ON\Data\Mapper\MappingContext;
use ON\Data\Mapper\Writer\WriterInterface;

final class OtherArrayWriter implements WriterInterface
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
		return [];
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
