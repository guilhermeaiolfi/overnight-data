<?php

declare(strict_types=1);

namespace ON\Data\Query\Projection;

/**
 * Query-owned read layout for assemble: flat place keys at each relation path.
 *
 * Built at the SelectQuery / ORM boundary from RepresentationSchema; LoadRuntime
 * must not import ORM types.
 */
interface ProjectionLayout
{
	/**
	 * Place keys for schema flats at this relation path (empty = root).
	 * Own-level explicit keys still come from selections.
	 *
	 * @param list<string> $relationPath
	 * @return list<string>
	 */
	public function flatPlaceKeysAt(array $relationPath): array;
}
