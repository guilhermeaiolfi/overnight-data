<?php

declare(strict_types=1);

namespace ON\Data\Query\Condition;

use ON\Data\Query\Expression\ValueExpressionInterface;

final class NullCondition implements ConditionInterface
{
	public function __construct(
		private readonly ValueExpressionInterface $expression,
		private readonly NullOperator $operator,
	) {
	}

	public function getExpression(): ValueExpressionInterface
	{
		return $this->expression;
	}

	public function getOperator(): NullOperator
	{
		return $this->operator;
	}
}
