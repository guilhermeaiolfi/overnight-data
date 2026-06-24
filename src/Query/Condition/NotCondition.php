<?php

declare(strict_types=1);

namespace ON\Data\Query\Condition;

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
}
