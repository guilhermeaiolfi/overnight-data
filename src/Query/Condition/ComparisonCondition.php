<?php

declare(strict_types=1);

namespace ON\Data\Query\Condition;

use ON\Data\Query\Expression\ValueExpressionInterface;
use ON\Data\Query\SourceMap;

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

	public function rebind(SourceMap $sources): self
	{
		$left = $this->left->rebind($sources);
		$right = $this->right->rebind($sources);

		if ($left === $this->left && $right === $this->right) {
			return $this;
		}

		return new self($left, $this->operator, $right);
	}
}
