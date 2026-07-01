<?php

declare(strict_types=1);

namespace ON\Data\Query\Condition;

use InvalidArgumentException;
use ON\Data\Query\QuerySourceInterface;

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

	public function rebaseFields(QuerySourceInterface $from, QuerySourceInterface $to): self
	{
		$changed = false;
		$conditions = [];

		foreach ($this->conditions as $condition) {
			$rebased = $condition->rebaseFields($from, $to);
			$changed = $changed || $rebased !== $condition;
			$conditions[] = $rebased;
		}

		if (! $changed) {
			return $this;
		}

		return new self($this->operator, $conditions);
	}
}
