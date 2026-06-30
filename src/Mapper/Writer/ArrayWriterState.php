<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Writer;

final class ArrayWriterState implements WriterStateInterface
{
	/**
	 * @var array<string|int, mixed>
	 */
	public array $items = [];
}
