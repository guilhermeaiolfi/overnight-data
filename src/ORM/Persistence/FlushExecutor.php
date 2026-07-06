<?php

declare(strict_types=1);

namespace ON\Data\ORM\Persistence;

use ON\Data\ORM\Relation\Persistence\RelationPersistencePlanner;
use ON\Data\ORM\SessionContext;
use ON\Data\ORM\State\RecordState;
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

		return $this->flushWithoutTransaction($context);
	}

	private function flushWithoutTransaction(SessionContext $context): FlushResult
	{
		$records = $context->getRecords();

		$syncResult = $this->syncer->sync($context);
		$relationResult = $this->relationPlanner->plan($context);

		$flush = $this->scheduler->run(
			$records,
			$relationResult->getCommands(),
			true,
		);

		$flush->finalize();

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
		$recordSnapshots = $this->snapshotRecords($context);

		try {
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
		} catch (\Throwable $exception) {
			$this->restoreRecords($recordSnapshots);

			throw $exception;
		}

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

	/**
	 * @return list<array{0: RecordState, 1: array{
	 *     key: \ON\Data\Key|null,
	 *     lifecycle: \ON\Data\ORM\State\RecordLifecycle,
	 *     revision: int,
	 *     originalValues: array<string, mixed>,
	 *     values: array<string, mixed>
	 * }}>
	 */
	private function snapshotRecords(SessionContext $context): array
	{
		$snapshots = [];
		foreach ($context->getRecords()->getAll() as $record) {
			$snapshots[] = [$record, $record->createPersistenceSnapshot()];
		}

		return $snapshots;
	}

	/**
	 * @param list<array{0: RecordState, 1: array{
	 *     key: \ON\Data\Key|null,
	 *     lifecycle: \ON\Data\ORM\State\RecordLifecycle,
	 *     revision: int,
	 *     originalValues: array<string, mixed>,
	 *     values: array<string, mixed>
	 * }}> $snapshots
	 */
	private function restoreRecords(array $snapshots): void
	{
		foreach ($snapshots as [$record, $snapshot]) {
			$record->restorePersistenceSnapshot($snapshot);
		}
	}
}
