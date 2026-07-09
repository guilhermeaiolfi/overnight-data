<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Sync;

final class SyncPlan
{
	/** @var list<SyncFieldUpdate> */
	private array $updates;
	/** @var list<SyncConflict> */
	private array $conflicts;

	/**
	 * @param list<SyncFieldUpdate> $updates
	 * @param list<SyncConflict> $conflicts
	 */
	public function __construct(array $updates, array $conflicts)
	{
		$this->updates = array_values($updates);
		$this->conflicts = array_values($conflicts);
	}

	/**
	 * @return list<SyncFieldUpdate>
	 */
	public function getUpdates(): array
	{
		return $this->updates;
	}

	/**
	 * @return list<SyncConflict>
	 */
	public function getConflicts(): array
	{
		return $this->conflicts;
	}

	public function hasUpdates(): bool
	{
		return $this->updates !== [];
	}

	public function hasConflicts(): bool
	{
		return $this->conflicts !== [];
	}

	public function isEmpty(): bool
	{
		return ! $this->hasUpdates() && ! $this->hasConflicts();
	}
}
