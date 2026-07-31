<?php

declare(strict_types=1);

namespace ON\Data\Query\Condition;

use InvalidArgumentException;
use ON\Data\Query\SourceMap;

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

	public function rebind(SourceMap $sources): self
	{
		$changed = false;
		$conditions = [];

		foreach ($this->conditions as $condition) {
			$bound = $condition->rebind($sources);
			$changed = $changed || $bound !== $condition;
			$conditions[] = $bound;
		}

		if (! $changed) {
			return $this;
		}

		return new self($this->operator, $conditions);
	}
}
