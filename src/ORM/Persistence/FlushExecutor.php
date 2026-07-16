<?php

declare(strict_types=1);

namespace ON\Data\ORM\Persistence;

use ON\Data\Key;
use ON\Data\ORM\Exception\NonTransactionalFlushException;
use ON\Data\ORM\Record\RecordLifecycle;
use ON\Data\ORM\Record\RecordState;
use ON\Data\ORM\Relation\Persistence\RelationPersistencePlanner;
use ON\Data\ORM\Representation\Sync\RepresentationSyncer;
use ON\Data\ORM\SessionContext;
use Throwable;

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
		if (! $this->executor instanceof TransactionalCommandExecutorInterface) {
			throw new NonTransactionalFlushException(sprintf(
				"Flush requires a command executor that implements %s; '%s' does not. Non-transactional flush is not supported.",
				TransactionalCommandExecutorInterface::class,
				$this->executor::class,
			));
		}

		return $this->flushInTransaction($this->executor, $context);
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

				// Sync already-tracked representations into RecordState / relation state.
				// Pending Session::update/create intents are NOT applied here — call Session::sync() first.
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
		} catch (Throwable $exception) {
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
	 *     key: Key|null,
	 *     lifecycle: RecordLifecycle,
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
	 *     key: Key|null,
	 *     lifecycle: RecordLifecycle,
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
