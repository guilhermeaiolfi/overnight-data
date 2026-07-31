<?php

declare(strict_types=1);

namespace ON\Data\Query\Expression;

use ON\Data\Query\SelectQuery;
use ON\Data\Query\SourceMap;

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

	public function rebind(SourceMap $sources): self
	{
		return new self($this->query->rebind($sources));
	}
}
