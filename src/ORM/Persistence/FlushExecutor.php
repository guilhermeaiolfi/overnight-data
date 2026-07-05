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
	private FlushScheduler $scheduler;
	private RelationPersistencePlanner $relationPlanner;

	public function __construct(
		CommandExecutorInterface $executor,
		?RepresentationSyncer $syncer = null,
		?FlushScheduler $scheduler = null,
		?RelationPersistencePlanner $relationPlanner = null,
	) {
		$this->executor = $executor;
		$this->syncer = $syncer ?? new RepresentationSyncer();
		$this->scheduler = $scheduler ?? new FlushScheduler($executor);
		$this->relationPlanner = $relationPlanner ?? new RelationPersistencePlanner();
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

		$flush = $this->scheduler->run(
			$records,
			$relationResult->getCommands(),
			false,
		);

		foreach ($relationResult->getChanges() as $change) {
			$change->clearChanges();
		}

		return new FlushResult(
			$syncResult->getSyncPlans(),
			$flush->getCommandResults(),
		);
	}

	private function flushInTransaction(
		TransactionalCommandExecutorInterface $transactionalExecutor,
		SessionContext $context,
	): FlushResult {
		$flush = null;
		$relationResult = null;

		$result = $transactionalExecutor->transaction(function () use (
			$context,
			&$flush,
			&$relationResult,
		): FlushResult {
			$records = $context->getRecords();

			$syncResult = $this->syncer->sync($context);
			$relationResult = $this->relationPlanner->plan($context);
			$flush = $this->scheduler->run(
				$records,
				$relationResult->getCommands(),
				true,
			);

			return new FlushResult(
				$syncResult->getSyncPlans(),
				$flush->getCommandResults(),
			);
		});

		if ($flush instanceof DeferredFlushResult) {
			$flush->finalize();
		}

		if ($relationResult !== null) {
			foreach ($relationResult->getChanges() as $change) {
				$change->clearChanges();
			}
		}

		return $result;
	}
}
