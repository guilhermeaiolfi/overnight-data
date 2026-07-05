<?php

declare(strict_types=1);

namespace ON\Data\ORM\Persistence;

use ON\Data\ORM\Relation\Persistence\RelationPersistencePlanner;
use ON\Data\ORM\Relation\ToManyRelationStore;
use ON\Data\ORM\Relation\ToOneRelationStore;
use ON\Data\ORM\State\RecordStateStore;
use ON\Data\ORM\State\RepresentationStore;
use ON\Data\ORM\Sync\RepresentationSyncer;

final class FlushExecutor
{
	private CommandExecutorInterface $executor;
	private RepresentationSyncer $syncer;
	private RecordFlusher $flusher;
	private RelationPersistencePlanner $relationPlanner;
	private CommandValueResolver $commandValueResolver;

	public function __construct(
		CommandExecutorInterface $executor,
		?RepresentationSyncer $syncer = null,
		?RecordFlusher $flusher = null,
		?RelationPersistencePlanner $relationPlanner = null,
		?CommandValueResolver $commandValueResolver = null,
	) {
		$this->executor = $executor;
		$this->syncer = $syncer ?? new RepresentationSyncer();
		$this->flusher = $flusher ?? new RecordFlusher($executor);
		$this->relationPlanner = $relationPlanner ?? new RelationPersistencePlanner();
		$this->commandValueResolver = $commandValueResolver ?? new CommandValueResolver();
	}

	public function flush(
		RepresentationStore $representations,
		RecordStateStore $records,
		?ToManyRelationStore $relations = null,
		?ToOneRelationStore $references = null,
	): FlushResult {
		$relations ??= new ToManyRelationStore();
		$references ??= new ToOneRelationStore();

		if ($this->executor instanceof TransactionalCommandExecutorInterface) {
			return $this->flushInTransaction($this->executor, $representations, $records, $relations, $references);
		}

		return $this->flushImmediately($representations, $records, $relations, $references);
	}

	private function flushImmediately(
		RepresentationStore $representations,
		RecordStateStore $records,
		ToManyRelationStore $relations,
		ToOneRelationStore $references,
	): FlushResult {
		$syncResult = $this->syncer->sync($representations, $records, $relations, $references);
		$relationResult = $this->relationPlanner->plan($relations, $references, $records, $representations);
		$commandResults = $this->flusher->flush($records);

		foreach ($relationResult->getCommands() as $command) {
			$this->commandValueResolver->assertReady($command);
			$commandResults[] = $this->executor->execute($command);
		}

		foreach ($relationResult->getChanges() as $change) {
			$change->clearChanges();
		}

		return new FlushResult($syncResult->getSyncPlans(), $commandResults);
	}

	private function flushInTransaction(
		TransactionalCommandExecutorInterface $transactionalExecutor,
		RepresentationStore $representations,
		RecordStateStore $records,
		ToManyRelationStore $relations,
		ToOneRelationStore $references,
	): FlushResult {
		$recordFlush = null;
		$relationResult = null;

		$result = $transactionalExecutor->transaction(function () use (
			$representations,
			$records,
			$relations,
			$references,
			&$recordFlush,
			&$relationResult,
		): FlushResult {
			$syncResult = $this->syncer->sync($representations, $records, $relations, $references);
			$relationResult = $this->relationPlanner->plan($relations, $references, $records, $representations);
			$recordFlush = $this->flusher->flushDeferred($records);
			$commandResults = $recordFlush->getCommandResults();

			foreach ($relationResult->getCommands() as $command) {
				$this->commandValueResolver->assertReady($command);
				$commandResults[] = $this->executor->execute($command);
			}

			return new FlushResult($syncResult->getSyncPlans(), $commandResults);
		});

		if ($recordFlush instanceof DeferredRecordFlushResult) {
			$recordFlush->finalize();
		}

		if ($relationResult !== null) {
			foreach ($relationResult->getChanges() as $change) {
				$change->clearChanges();
			}
		}

		return $result;
	}
}
