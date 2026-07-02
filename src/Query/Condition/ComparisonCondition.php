<?php

declare(strict_types=1);

namespace ON\Data\Query\Condition;

use ON\Data\Query\Expression\ValueExpressionInterface;
use ON\Data\Query\QuerySourceInterface;

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

	public function rebaseFields(QuerySourceInterface $from, QuerySourceInterface $to): self
	{
		return $this->bindTo($to, from: $from);
	}

	public function bindTo(QuerySourceInterface $target, ?QuerySourceInterface $from = null): self
	{
		$left = $this->left->bindTo($target, from: $from);
		$right = $this->right->bindTo($target, from: $from);

		if ($left === $this->left && $right === $this->right) {
			return $this;
		}

		return new self($left, $this->operator, $right);
	}
}
