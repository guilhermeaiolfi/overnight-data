<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Writer;

use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\MappingOptions;

interface WriterInterface
{
	public static function canWrite(
		mixed $target,
		MappingOptions $options,
	): bool;

	public function createTarget(
		MappingNode $node,
	): mixed;

	public function write(
		mixed $target,
		string|int $name,
		mixed $value,
		MappingNode $node,
	): mixed;
}
