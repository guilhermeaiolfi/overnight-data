<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\MappingOptions;
use ON\Data\Mapper\Writer\WriterInterface;

final class OtherArrayWriter implements WriterInterface
{
	public static function canWrite(
		mixed $target,
		MappingOptions $options,
	): bool {
		return is_array($target);
	}

	public function createTarget(MappingNode $node): array
	{
		return [];
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
