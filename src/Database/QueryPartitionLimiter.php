<?php

declare(strict_types=1);

namespace ON\Data\Database;

use ON\Data\Query\QuerySourceInterface;
use ON\Data\Query\SelectQuery;
use ON\Data\Query\Sort\Sort;

/**
 * @internal
 */
interface QueryPartitionLimiter
{
	/**
	 * @param non-empty-list<string> $partitionFields
	 * @param non-empty-list<Sort> $orderBy
	 */
	public function applyPartitionedLimit(
		SelectQuery $query,
		QuerySourceInterface $source,
		array $partitionFields,
		array $orderBy,
		int $limit,
		int $offset,
		string $rowNumberAlias,
	): SelectQuery;
}
