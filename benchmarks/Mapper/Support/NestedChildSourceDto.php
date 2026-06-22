<?php

declare(strict_types=1);

namespace Benchmarks\ON\Data\Mapper\Support;

use ON\Data\Mapper\Attribute\MapTo;

final class NestedChildSourceDto
{
	public int $id;

	#[MapTo('display_name')]
	public string $name;

	public bool $active;
	public float $score;
}
