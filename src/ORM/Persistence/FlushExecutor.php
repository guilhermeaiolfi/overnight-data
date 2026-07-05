<?php

declare(strict_types=1);

namespace ON\Data\ORM\Persistence;

use ON\Data\ORM\Relation\Persistence\RelationPersistencePlanner;
use ON\Data\ORM\Relation\RelatedCollectionMap;
use ON\Data\ORM\Relation\RelatedReferenceMap;
use ON\Data\ORM\State\RecordStateMap;
use ON\Data\ORM\State\TrackedRepresentationMap;
use ON\Data\ORM\Sync\RepresentationSyncer;

final class FlushExecutor
{
	private CommandExecutorInterface $executor;
	private RepresentationSyncer $syncer;
	private RecordFlusher $flusher;
	private RelationPersistencePlanner $relationPlanner;

	public function __construct(
		CommandExecutorInterface $executor,
		?RepresentationSyncer $syncer = null,
		?RecordFlusher $flusher = null,
		?RelationPersistencePlanner $relationPlanner = null,
	) {
		$this->executor = $executor;
		$this->syncer = $syncer ?? new RepresentationSyncer();
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
		$syncResult = $this->syncer->sync($representations, $records, $relations, $references);
		$relationResult = $this->relationPlanner->plan($relations, $references, $records, $representations);
		$commandResults = $this->flusher->flush($records);

		foreach ($relationResult->getCommands() as $command) {
			$commandResults[] = $this->executor->execute($command);
		}

		foreach ($relationResult->getChanges() as $change) {
			$change->clearChanges();
		}

		return new FlushResult($syncResult->getSyncPlans(), $commandResults);
	}
}
