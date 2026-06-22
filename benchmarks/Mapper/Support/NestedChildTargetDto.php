<?php

declare(strict_types=1);

namespace Benchmarks\ON\Data\Mapper\Support;

use ON\Data\Mapper\Attribute\MapFrom;

final class NestedChildTargetDto
{
	public int $id;

	#[MapFrom('display_name')]
	public string $name;

	public bool $active;
	public float $score;
}
