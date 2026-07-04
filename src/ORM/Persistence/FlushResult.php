<?php

declare(strict_types=1);

namespace ON\Data\ORM\Persistence;

use ON\Data\ORM\Sync\SyncPlan;

final class FlushResult
{
	/** @var list<SyncPlan> */
	private array $syncPlans;

	/** @var list<CommandResult> */
	private array $commandResults;

	/**
	 * @param list<SyncPlan> $syncPlans
	 * @param list<CommandResult> $commandResults
	 */
	public function __construct(array $syncPlans, array $commandResults)
	{
		$this->syncPlans = array_values($syncPlans);
		$this->commandResults = array_values($commandResults);
	}

	/**
	 * @return list<SyncPlan>
	 */
	public function getSyncPlans(): array
	{
		return $this->syncPlans;
	}

	/**
	 * @return list<CommandResult>
	 */
	public function getCommandResults(): array
	{
		return $this->commandResults;
	}
}
