<?php

declare(strict_types=1);

namespace ON\Data\ORM\Persistence;

use ON\Data\ORM\Relation\Persistence\RelationPersistencePlanner;
use ON\Data\ORM\SessionContext;
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

	public function flush(SessionContext $context): FlushResult
	{
		if ($this->executor instanceof TransactionalCommandExecutorInterface) {
			return $this->flushInTransaction($this->executor, $context);
		}

		return $this->flushImmediately($context);
	}

	private function flushImmediately(SessionContext $context): FlushResult
	{
		$records = $context->getRecords();

		$syncResult = $this->syncer->sync($context);
		$relationResult = $this->relationPlanner->plan($context);
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
		SessionContext $context,
	): FlushResult {
		$recordFlush = null;
		$relationResult = null;

		$result = $transactionalExecutor->transaction(function () use (
			$context,
			&$recordFlush,
			&$relationResult,
		): FlushResult {
			$records = $context->getRecords();

			$syncResult = $this->syncer->sync($context);
			$relationResult = $this->relationPlanner->plan($context);
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
