<?php

declare(strict_types=1);

namespace ON\Data\Query\Sort;

use ON\Data\Query\Expression\ValueExpressionInterface;
use ON\Data\Query\SourceMap;

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

	public function rebind(SourceMap $sources): self
	{
		$expression = $this->expression->rebind($sources);

		if ($expression === $this->expression) {
			return $this;
		}

		return new self($expression, $this->direction);
	}
}
