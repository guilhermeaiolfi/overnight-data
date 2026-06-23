<?php

declare(strict_types=1);

namespace ON\Data\Query\Condition;

use InvalidArgumentException;

final class LogicalCondition implements ConditionInterface
{
	/**
	 * @param non-empty-list<ConditionInterface> $conditions
	 */
	public function __construct(
		private readonly LogicalOperator $operator,
		private readonly array $conditions,
	) {
		if ($this->conditions === []) {
			throw new InvalidArgumentException('LogicalCondition requires at least one condition.');
		}
	}

	public function getOperator(): LogicalOperator
	{
		return $this->operator;
	}

	/**
	 * @return non-empty-list<ConditionInterface>
	 */
	public function getConditions(): array
	{
		return $this->conditions;
	}
}
