<?php

declare(strict_types=1);

namespace ON\Data\Query\Result;

use ON\Data\Query\SelectQuery;

/**
 * Bridge for writable object export: prepare the query (e.g. hidden identity
 * selections), then track materialized objects into persistence state.
 *
 * Defined in Query so {@see SelectQuery} does not depend on ORM types.
 * Session is the usual implementation callers pass to {@see SelectQuery::writable()}.
 */
interface WritableResultHandler
{
	/**
	 * Prepare a query for writable export before rows are fetched.
	 *
	 * May mutate the query (for example by adding INTERNAL identity selections).
	 * The returned token is owned by the caller for this fetch and passed to
	 * {@see track()} (or discarded if the fetch yields nothing to track).
	 */
	public function prepare(SelectQuery $query): WritablePreparation;

	/**
	 * Track materialized writable objects after fetch.
	 *
	 * @param list<array<string, mixed>> $rawRows full rows including INTERNAL keys
	 * @param list<object>               $objects public objects in the same order as $rawRows
	 */
	public function track(
		SelectQuery $query,
		WritablePreparation $preparation,
		array $rawRows,
		array $objects,
	): void;
}
