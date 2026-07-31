<?php

declare(strict_types=1);

namespace ON\Data\Query\Condition;

use ON\Data\Query\SourceMap;

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

	public function rebind(SourceMap $sources): self
	{
		$condition = $this->condition->rebind($sources);

		if ($condition === $this->condition) {
			return $this;
		}

		return new self($condition);
	}
}
