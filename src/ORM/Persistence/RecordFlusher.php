<?php

declare(strict_types=1);

namespace ON\Data\ORM\Persistence;

use ON\Data\ORM\State\RecordStateStore;

final class RecordFlusher
{
	private FlushScheduler $scheduler;

	public function __construct(
		CommandExecutorInterface $executor,
		?CommandPlanner $planner = null,
	) {
		$this->scheduler = new FlushScheduler($executor, $planner);
	}

	/**
	 * @return list<CommandResult>
	 */
	public function flush(RecordStateStore $states): array
	{
		return $this->scheduler->run($states)->getCommandResults();
	}

	public function flushDeferred(RecordStateStore $states): DeferredFlushResult
	{
		return $this->scheduler->run($states, [], true);
	}
}
