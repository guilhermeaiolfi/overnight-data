<?php

declare(strict_types=1);

namespace ON\Data\Query\Condition;

use ON\Data\Query\Expression\ValueExpressionInterface;

final class ComparisonCondition implements ConditionInterface
{
	public function __construct(
		private readonly ValueExpressionInterface $left,
		private readonly ComparisonOperator $operator,
		private readonly ValueExpressionInterface $right,
	) {
	}

	public function getLeft(): ValueExpressionInterface
	{
		return $this->left;
	}

	public function getOperator(): ComparisonOperator
	{
		return $this->operator;
	}

	public function getRight(): ValueExpressionInterface
	{
		return $this->right;
	}
}
