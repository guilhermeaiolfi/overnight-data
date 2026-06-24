<?php

declare(strict_types=1);

namespace ON\Data\Query\Sort;

use ON\Data\Query\Expression\ValueExpressionInterface;

final class Sort
{
	public function __construct(
		private readonly ValueExpressionInterface $expression,
		private readonly SortDirection $direction,
	) {
	}

	public function getExpression(): ValueExpressionInterface
	{
		return $this->expression;
	}

	public function getDirection(): SortDirection
	{
		return $this->direction;
	}
}
