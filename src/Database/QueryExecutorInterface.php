<?php

declare(strict_types=1);

namespace ON\Data\Database;

use ON\Data\Query\SelectQuery;

interface QueryExecutorInterface
{
	/**
	 * @return list<array<string, mixed>>
	 */
	public function fetchAll(SelectQuery $query): array;

	/**
	 * @return array<string, mixed>|null
	 */
	public function fetchOne(SelectQuery $query): ?array;

	/**
	 * @return iterable<array<string, mixed>>
	 */
	public function iterate(SelectQuery $query): iterable;
}
