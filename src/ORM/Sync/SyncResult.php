<?php

declare(strict_types=1);

namespace ON\Data\ORM\Sync;

use ON\Data\ORM\Relation\RelationChangeInterface;

final class SyncResult
{
	/** @var list<SyncPlan> */
	private array $syncPlans;
	/** @var list<RelationChangeInterface> */
	private array $relationChanges;

	/**
	 * @param list<SyncPlan> $syncPlans
	 * @param list<RelationChangeInterface> $relationChanges
	 */
	public function __construct(array $syncPlans, array $relationChanges)
	{
		$this->syncPlans = array_values($syncPlans);
		$this->relationChanges = array_values($relationChanges);
	}

	/**
	 * @return list<SyncPlan>
	 */
	public function getSyncPlans(): array
	{
		return $this->syncPlans;
	}

	/**
	 * @return list<RelationChangeInterface>
	 */
	public function getRelationChanges(): array
	{
		return $this->relationChanges;
	}

	public function hasChanges(): bool
	{
		foreach ($this->syncPlans as $plan) {
			if ($plan->hasUpdates()) {
				return true;
			}
		}

		foreach ($this->relationChanges as $change) {
			if ($change->hasChanges()) {
				return true;
			}
		}

		return false;
	}
}
