<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation;

/**
 * In-memory record holder for one loaded row during fetch assembly.
 *
 * Keeps scalar values separate from attached child relations so field names
 * never collide with relation container names and shared targets (e.g. BelongsTo
 * or M2M) can be referenced by multiple parents without PHP `&` references.
 */
final class ResultRecord
{
	/**
	 * @var array<string, self|list<self>|null>
	 */
	public array $children = [];

	/**
	 * @param array<string, mixed> $values
	 */
	public function __construct(
		public array $values,
	) {
	}
}
