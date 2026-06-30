<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Writer;

use ON\Data\Mapper\MappingNode;

final class ObjectWriterState implements WriterStateInterface
{
	public ?object $target = null;

	/**
	 * @var array<string|int, mixed>
	 */
	public array $values = [];

	/**
	 * @var list<array{name: string|int, value: mixed, node: MappingNode}>
	 */
	public array $writes = [];
}
