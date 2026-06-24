<?php

declare(strict_types=1);

namespace ON\Data\Query\Condition;

use ON\Data\Query\SelectQuery;

final class ExistsCondition implements ConditionInterface
{
	public function __construct(
		private readonly SelectQuery $query,
		private readonly bool $negated = false,
	) {
	}

	public function getQuery(): SelectQuery
	{
		return $this->query;
	}

	public function isNegated(): bool
	{
		return $this->negated;
	}
}
