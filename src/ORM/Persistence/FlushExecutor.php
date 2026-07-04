<?php

declare(strict_types=1);

namespace ON\Data\ORM\Persistence;

use ON\Data\ORM\State\RecordStateMap;
use ON\Data\ORM\State\TrackedRepresentationMap;
use ON\Data\ORM\Sync\RepresentationSynchronizer;

final class FlushExecutor
{
	private RepresentationSynchronizer $synchronizer;
	private RecordFlusher $flusher;

	public function __construct(
		CommandExecutorInterface $executor,
		?RepresentationSynchronizer $synchronizer = null,
		?RecordFlusher $flusher = null,
	) {
		$this->synchronizer = $synchronizer ?? new RepresentationSynchronizer();
		$this->flusher = $flusher ?? new RecordFlusher($executor);
	}

	public function flush(TrackedRepresentationMap $representations, RecordStateMap $records): FlushResult
	{
		$syncPlans = $this->synchronizer->sync($representations, $records);
		$commandResults = $this->flusher->flush($records);

		return new FlushResult($syncPlans, $commandResults);
	}
}
