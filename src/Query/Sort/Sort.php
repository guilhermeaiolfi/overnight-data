<?php

declare(strict_types=1);

namespace ON\Data\Query\Sort;

use ON\Data\Query\Expression\ValueExpressionInterface;
use ON\Data\Query\QuerySourceInterface;

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

	public function bindTo(QuerySourceInterface $target, ?QuerySourceInterface $from = null): self
	{
		$expression = $this->expression->bindTo($target, from: $from);

		if ($expression === $this->expression) {
			return $this;
		}

		return new self($expression, $this->direction);
	}
}
