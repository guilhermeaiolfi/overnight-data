<?php

declare(strict_types=1);

namespace ON\Data\Query\Expression;

final class AggregateExpression extends AbstractValueExpression
{
	public function __construct(
		private readonly AggregateFunction $function,
		private readonly ValueExpressionInterface|StarExpression $expression,
	) {
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
