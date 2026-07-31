<?php

declare(strict_types=1);

namespace ON\Data\Query\Condition;

use ON\Data\Query\Expression\ValueExpressionInterface;
use ON\Data\Query\SourceMap;

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

	public function rebind(SourceMap $sources): self
	{
		$expression = $this->expression->rebind($sources);

		if ($expression === $this->expression) {
			return $this;
		}

		return new self($expression, $this->operator);
	}
}
