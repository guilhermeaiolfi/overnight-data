<?php

declare(strict_types=1);

namespace Benchmarks\ON\Data\Mapper\Support;

final class MinimalNestedTargetDto
{
	/** @var list<MinimalNestedChildTargetDto> */
	public array $children = [];
}
