<?php

declare(strict_types=1);

namespace ON\Data\Query\Condition;

use ON\Data\Query\QuerySourceInterface;

final class NotCondition implements ConditionInterface
{
	public function __construct(
		private readonly ConditionInterface $condition,
	) {
	}

	public function getCondition(): ConditionInterface
	{
		return $this->condition;
	}

	public function rebaseFields(QuerySourceInterface $from, QuerySourceInterface $to): self
	{
		$condition = $this->condition->rebaseFields($from, $to);

		if ($condition === $this->condition) {
			return $this;
		}

		return new self($condition);
	}
}
