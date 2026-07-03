<?php

declare(strict_types=1);

namespace ON\Data\ORM\Relation;

final class RelationCollectionChangeSet
{
	/**
	 * @param list<object> $added
	 * @param list<object> $removed
	 */
	public function __construct(
		private readonly array $added,
		private readonly array $removed,
		private readonly bool $fullReplacement = false,
	) {
	}

	/**
	 * @return list<object>
	 */
	public function getAdded(): array
	{
		return $this->added;
	}

	/**
	 * @return list<object>
	 */
	public function getRemoved(): array
	{
		return $this->removed;
	}

	public function isFullReplacement(): bool
	{
		return $this->fullReplacement;
	}

	public function isEmpty(): bool
	{
		return $this->added === []
			&& $this->removed === []
			&& ! $this->fullReplacement;
	}
}
