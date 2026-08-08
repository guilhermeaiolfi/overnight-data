<?php

declare(strict_types=1);

namespace ON\Data\Query\Projection;

use ON\Data\Query\Relation\RelationSelection;

/**
 * Immutable flat-key map keyed by {@see RelationSelection::pathKey()}.
 */
final class ArrayProjectionLayout implements ProjectionLayout
{
	/**
	 * @param array<string, list<string>> $flatPlaceKeysByPathKey
	 */
	public function __construct(
		private readonly array $flatPlaceKeysByPathKey,
	) {
	}

	public function flatPlaceKeysAt(array $relationPath): array
	{
		return $this->flatPlaceKeysByPathKey[RelationSelection::pathKey($relationPath)] ?? [];
	}
}
