<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Writer;

use ON\Data\Mapper\MappingContext;

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
		string|int $name,
		mixed $value,
		MappingContext $context,
		mixed $walkerArguments = null,
	): mixed;

	public function finish(
		mixed $target,
		MappingContext $context,
	): mixed;
}
