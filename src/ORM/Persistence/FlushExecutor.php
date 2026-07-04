<?php

declare(strict_types=1);

namespace ON\Data\ORM\Persistence;

use ON\Data\ORM\Relation\Persistence\RelationPersistenceSynchronizer;
use ON\Data\ORM\Relation\RelatedCollectionMap;
use ON\Data\ORM\State\RecordStateMap;
use ON\Data\ORM\State\TrackedRepresentationMap;
use ON\Data\ORM\Sync\RepresentationSynchronizer;

final class FlushExecutor
{
	private CommandExecutorInterface $executor;
	private RepresentationSynchronizer $synchronizer;
	private RecordFlusher $flusher;
	private RelationPersistenceSynchronizer $relationSynchronizer;

	public function __construct(
		CommandExecutorInterface $executor,
		?RepresentationSynchronizer $synchronizer = null,
		?RecordFlusher $flusher = null,
		?RelationPersistenceSynchronizer $relationSynchronizer = null,
	) {
		$this->executor = $executor;
		$this->synchronizer = $synchronizer ?? new RepresentationSynchronizer();
		$this->flusher = $flusher ?? new RecordFlusher($executor);
		$this->relationSynchronizer = $relationSynchronizer ?? new RelationPersistenceSynchronizer();
	}

	public function flush(
		TrackedRepresentationMap $representations,
		RecordStateMap $records,
		?RelatedCollectionMap $relations = null,
	): FlushResult
	{
		$relations ??= new RelatedCollectionMap();
		$syncPlans = $this->synchronizer->sync($representations, $records);
		$relationResult = $this->relationSynchronizer->sync($relations, $records, $representations);
		$commandResults = $this->flusher->flush($records);

		foreach ($relationResult->getCommands() as $command) {
			$commandResults[] = $this->executor->execute($command);
		}

		foreach ($relationResult->getCollections() as $collection) {
			$collection->clearChanges();
		}

		return new FlushResult($syncPlans, $commandResults);
	}
}
