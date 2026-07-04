<?php

declare(strict_types=1);

namespace ON\Data\ORM\Persistence;

use ON\Data\ORM\Relation\Persistence\RelationPersistencePlanner;
use ON\Data\ORM\Relation\RelatedCollectionMap;
use ON\Data\ORM\Relation\RelatedReferenceMap;
use ON\Data\ORM\State\RecordStateMap;
use ON\Data\ORM\State\TrackedRepresentationMap;
use ON\Data\ORM\Sync\RelationRepresentationSynchronizer;
use ON\Data\ORM\Sync\ScalarRepresentationSynchronizer;

final class FlushExecutor
{
	private CommandExecutorInterface $executor;
	private ScalarRepresentationSynchronizer $scalarSynchronizer;
	private RelationRepresentationSynchronizer $relationSynchronizer;
	private RecordFlusher $flusher;
	private RelationPersistencePlanner $relationPlanner;

	public function __construct(
		CommandExecutorInterface $executor,
		?ScalarRepresentationSynchronizer $scalarSynchronizer = null,
		?RecordFlusher $flusher = null,
		?RelationPersistencePlanner $relationPlanner = null,
		?RelationRepresentationSynchronizer $relationSynchronizer = null,
	) {
		$this->executor = $executor;
		$this->scalarSynchronizer = $scalarSynchronizer ?? new ScalarRepresentationSynchronizer();
		$this->relationSynchronizer = $relationSynchronizer ?? new RelationRepresentationSynchronizer();
		$this->flusher = $flusher ?? new RecordFlusher($executor);
		$this->relationPlanner = $relationPlanner ?? new RelationPersistencePlanner();
	}

	public function flush(
		TrackedRepresentationMap $representations,
		RecordStateMap $records,
		?RelatedCollectionMap $relations = null,
		?RelatedReferenceMap $references = null,
	): FlushResult
	{
		$relations ??= new RelatedCollectionMap();
		$references ??= new RelatedReferenceMap();
		$syncPlans = $this->scalarSynchronizer->sync($representations, $records);
		$this->relationSynchronizer->sync($representations, $relations, $references);
		$relationResult = $this->relationPlanner->plan($relations, $references, $records, $representations);
		$commandResults = $this->flusher->flush($records);

		foreach ($relationResult->getCommands() as $command) {
			$commandResults[] = $this->executor->execute($command);
		}

		foreach ($relationResult->getChanges() as $change) {
			$change->clearChanges();
		}

		return new FlushResult($syncPlans, $commandResults);
	}
}
