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

	public function bindTo(QuerySourceInterface $target, ?QuerySourceInterface $from = null): self
	{
		$condition = $this->condition->bindTo($target, from: $from);

		if ($condition === $this->condition) {
			return $this;
		}

		return new self($condition);
	}
}
