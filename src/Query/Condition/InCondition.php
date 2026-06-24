<?php

declare(strict_types=1);

namespace ON\Data\Query\Condition;

use InvalidArgumentException;
use ON\Data\Query\Expression\SubqueryExpression;
use ON\Data\Query\Expression\ValueExpressionInterface;

final class InCondition implements ConditionInterface
{
	/**
	 * @param non-empty-list<ValueExpressionInterface>|SubqueryExpression $set
	 */
	public function __construct(
		private readonly ValueExpressionInterface $expression,
		private readonly array|SubqueryExpression $set,
		private readonly bool $negated = false,
	) {
		if (is_array($this->set) && $this->set === []) {
			throw new InvalidArgumentException('InCondition requires a non-empty set.');
		}
	}

	public function getExpression(): ValueExpressionInterface
	{
		return $this->expression;
	}

	/**
	 * @return non-empty-list<ValueExpressionInterface>|SubqueryExpression
	 */
	public function getSet(): array|SubqueryExpression
	{
		return $this->set;
	}

	public function isNegated(): bool
	{
		return $this->negated;
	}
}
