<?php

declare(strict_types=1);

namespace ON\Data\Query\Result;

use ON\Data\Query\SelectQuery;

/**
 * Bridge for mutable object export: prepare the query (e.g. hidden identity
 * selections), then track materialized objects into persistence state.
 *
 * Defined in Query so {@see SelectQuery} does not depend on ORM types.
 * Session is the usual implementation callers pass to {@see SelectQuery::mutable()}.
 */
interface MutableResultHandler
{
	/**
	 * Prepare a query for mutable export before rows are fetched.
	 *
	 * May mutate the query (for example by adding INTERNAL identity selections).
	 * The returned token is owned by the caller for this fetch and passed to
	 * {@see track()} (or discarded if the fetch yields nothing to track).
	 */
	public function prepare(SelectQuery $query): MutablePreparation;

	/**
	 * Track materialized mutable objects after fetch.
	 *
	 * @param list<array<string, mixed>> $rawRows full rows including INTERNAL keys
	 * @param list<object>               $objects public objects in the same order as $rawRows
	 */
	public function track(
		SelectQuery $query,
		MutablePreparation $preparation,
		array $rawRows,
		array $objects,
	): void;
}
