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

	public function prepare(
		mixed $target,
		MappingContext $context,
	): mixed;

	public function write(
		mixed $target,
		MappingNode $node,
		mixed $value,
	): mixed;

	public function finish(
		mixed $target,
		MappingContext $context,
	): mixed;
}
