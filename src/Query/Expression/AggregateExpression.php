<?php

declare(strict_types=1);

namespace ON\Data\Query\Expression;

use InvalidArgumentException;

final class AggregateExpression extends AbstractValueExpression
{
	public function __construct(
		private readonly AggregateFunction $function,
		private readonly ValueExpressionInterface|StarExpression $expression,
	) {
		if ($this->expression instanceof self) {
			throw new InvalidArgumentException('Aggregate expressions cannot be aggregated directly.');
		}

		if ($this->expression instanceof StarExpression && $this->function !== AggregateFunction::COUNT) {
			throw new InvalidArgumentException('Only COUNT may use a StarExpression operand.');
		}
	}

	public function getFunction(): AggregateFunction
	{
		return $this->function;
	}

	public function getExpression(): ValueExpressionInterface|StarExpression
	{
		return $this->expression;
	}
}
