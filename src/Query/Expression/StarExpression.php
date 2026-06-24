<?php

declare(strict_types=1);

namespace ON\Data\Query\Expression;

use ON\Data\Query\ExpressionFactory;
use ON\Data\Query\SelectQuery;
use function ON\Data\Query\x;

final class StarExpression
{
	public function __construct(
		private readonly SelectQuery $query,
	) {
	}

	public function getQuery(): SelectQuery
	{
		return $this->query;
	}

	public function count(): AggregateExpression
	{
		return $this->factory()->count($this);
	}

	private function factory(): ExpressionFactory
	{
		return x();
	}
}
