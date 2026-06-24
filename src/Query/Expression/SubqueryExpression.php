<?php

declare(strict_types=1);

namespace ON\Data\Query\Expression;

use ON\Data\Query\SelectQuery;

final class SubqueryExpression extends AbstractAggregateableExpression
{
	public function __construct(
		private readonly SelectQuery $query,
	) {
	}

	public function getQuery(): SelectQuery
	{
		return $this->query;
	}
}
