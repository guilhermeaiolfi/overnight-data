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

	public function createState(
		MappingNode $node,
	): WriterStateInterface;

	public function write(
		WriterStateInterface $state,
		string|int $name,
		mixed $value,
		MappingNode $node,
	): void;

	public function getResult(
		WriterStateInterface $state,
		MappingNode $node,
	): mixed;
}
