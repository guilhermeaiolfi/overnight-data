<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Writer;

use ON\Data\Mapper\MappingContext;
use ON\Data\Mapper\MappingNode;

interface WriterInterface
{
	public static function canWrite(
		mixed $target,
		MappingContext $context,
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
