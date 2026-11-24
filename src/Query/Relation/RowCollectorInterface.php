<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation;

/**
 * Consumes one fetched row for a relation branch, storing any resulting
 * {@see ResultRecord} and attaching it to matching parent record(s).
 *
 * Returning the produced {@see ResultRecord} allows joined descendants on the
 * same SQL row to attach underneath it; returning null skips descendants (for
 * example on a LEFT JOIN miss).
 */
interface RowCollectorInterface
{
	/**
	 * @param array<string, mixed> $row
	 */
	public function collect(
		RelationLoadBranch $branch,
		array $row,
		?ResultRecord $joinedParent,
	): ?ResultRecord;
}
