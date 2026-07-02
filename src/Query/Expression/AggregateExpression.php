<?php

declare(strict_types=1);

namespace ON\Data\Query\Expression;

use InvalidArgumentException;
use ON\Data\Query\QuerySourceInterface;

final class AggregateExpression extends AbstractValueExpression
{
	public function __construct(
		private readonly AggregateFunction $function,
		private readonly ValueExpressionInterface|StarExpression $expression,
	) {
		if ($this->expression instanceof self) {
			throw new InvalidArgumentException('Aggregate expressions cannot be aggregated directly.');
		}

		if ($this->containsAggregateAtCurrentLevel($this->expression)) {
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

	public function bindTo(QuerySourceInterface $target, ?QuerySourceInterface $from = null): self
	{
		if ($this->expression instanceof StarExpression) {
			return $this;
		}

		$expression = $this->expression->bindTo($target, from: $from);

		if ($expression === $this->expression) {
			return $this;
		}

		return new self($this->function, $expression);
	}

	private function containsAggregateAtCurrentLevel(ValueExpressionInterface|StarExpression $expression): bool
	{
		if ($expression instanceof StarExpression) {
			return false;
		}

		if ($expression instanceof self) {
			return true;
		}

		if (! $expression instanceof ValueOperationExpression) {
			return false;
		}

		foreach ($expression->getArguments() as $argument) {
			if ($this->containsAggregateAtCurrentLevel($argument)) {
				return true;
			}
		}

		return false;
	}
}
