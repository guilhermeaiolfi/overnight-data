<?php

declare(strict_types=1);

namespace ON\Data\Query\Condition;

use ON\Data\Query\QuerySourceInterface;
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

	public function rebaseFields(QuerySourceInterface $from, QuerySourceInterface $to): self
	{
		return $this->bindTo($to, from: $from);
	}

	public function bindTo(QuerySourceInterface $target, ?QuerySourceInterface $from = null): self
	{
		return $this;
	}
}
