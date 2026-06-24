<?php

declare(strict_types=1);

namespace ON\Data\Database\Cycle;

use Cycle\Database\Query\SelectQuery as CycleSelectQuery;

/**
 * @internal
 */
final class CycleQueryPlan
{
	/**
	 * @param list<CycleResultColumn> $columns
	 */
	public function __construct(
		private readonly CycleSelectQuery $query,
		private readonly array $columns,
	) {
	}

	public function query(): CycleSelectQuery
	{
		return $this->query;
	}

	/**
	 * @return list<CycleResultColumn>
	 */
	public function columns(): array
	{
		return $this->columns;
	}
}
