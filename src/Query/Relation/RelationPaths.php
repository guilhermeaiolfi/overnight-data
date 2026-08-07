<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation;

/**
 * Shared relation-path helpers for LoadGraph, schema compile, and identity planning
 * (proposal 0003 Phase 4).
 */
final class RelationPaths
{
	/**
	 * True when $descendant is a strict nested path under $ancestor on the same query.
	 */
	public static function isUnder(RelationRef $ancestor, RelationRef $descendant): bool
	{
		if ($descendant->getQuery() !== $ancestor->getQuery()) {
			return false;
		}

		$ancestorPath = $ancestor->getPath();
		$descendantPath = $descendant->getPath();

		if (count($descendantPath) <= count($ancestorPath)) {
			return false;
		}

		return array_slice($descendantPath, 0, count($ancestorPath)) === $ancestorPath;
	}

	/**
	 * Path segments of $descendant relative to $ancestor (requires {@see isUnder()}).
	 *
	 * @return list<string>
	 */
	public static function relativeTo(RelationRef $ancestor, RelationRef $descendant): array
	{
		return array_values(array_slice($descendant->getPath(), count($ancestor->getPath())));
	}
}
