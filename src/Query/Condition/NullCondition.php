<?php

declare(strict_types=1);

namespace ON\Data\Query\Condition;

use ON\Data\Query\Expression\ValueExpressionInterface;
use ON\Data\Query\QuerySourceInterface;

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

	public function rebaseFields(QuerySourceInterface $from, QuerySourceInterface $to): self
	{
		return $this->bindTo($to, from: $from);
	}

	public function bindTo(QuerySourceInterface $target, ?QuerySourceInterface $from = null): self
	{
		$expression = $this->expression->bindTo($target, from: $from);

		if ($expression === $this->expression) {
			return $this;
		}

		return new self($expression, $this->operator);
	}
}
