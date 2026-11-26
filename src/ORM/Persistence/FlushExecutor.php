<?php

declare(strict_types=1);

namespace ON\Data\ORM\Persistence;

use Closure;
use ON\Data\Key;
use ON\Data\ORM\Exception\NonTransactionalFlushException;
use ON\Data\ORM\History\FlushCapture;
use ON\Data\ORM\History\FlushHistory;
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
		private ?FlushHistory $history = null,
	) {
		$this->executor = $executor;
		$this->syncer = $syncer ?? new RepresentationSyncer();
		$this->scheduler = $scheduler ?? new FlushScheduler($executor);
		$this->relationPlanner = $relationPlanner ?? new RelationPersistencePlanner();
	}

	public function getHistory(): ?FlushHistory
	{
		return $this->history;
	}

	public function withHistory(FlushHistory $history): self
	{
		$flusher = clone $this;
		$flusher->history = $history;

		return $flusher;
	}

	/** @param (Closure(): void)|null $prepare Session preparation to run before persistence under the history guard. */
	public function flush(SessionContext $context, ?string $label = null, ?Closure $prepare = null): FlushResult
	{
		$executor = $this->executor;
		if (! $executor instanceof TransactionalCommandExecutorInterface) {
			throw new NonTransactionalFlushException(sprintf(
				"Flush requires a command executor that implements %s; '%s' does not. Non-transactional flush is not supported.",
				TransactionalCommandExecutorInterface::class,
				$this->executor::class,
			));
		}

		if ($this->history !== null) {
			return $this->history->whileWriting(
				$executor,
				fn (): FlushResult => $this->flushInTransaction($executor, $context, $prepare, new FlushCapture(), $label),
			);
		}

		return $this->flushInTransaction($executor, $context, $prepare);
	}

	private function flushInTransaction(
		TransactionalCommandExecutorInterface $transactionalExecutor,
		SessionContext $context,
		?Closure $prepare,
		?FlushCapture $capture = null,
		?string $label = null,
	): FlushResult {
		$flush = null;
		$relationResult = null;
		$recordSnapshots = [];
		$representationSnapshots = [];

		try {
			$result = $transactionalExecutor->transaction(function () use (
				$context,
				$capture,
				$prepare,
				&$recordSnapshots,
				&$representationSnapshots,
				&$flush,
				&$relationResult,
			): FlushResult {
				$prepare?->__invoke();
				$recordSnapshots = $this->snapshotRecords($context);
				foreach ($context->getRepresentations()->getAll() as $state) {
					$representationSnapshots[] = [$state, $state->snapshotBaselines()];
				}
				$records = $context->getRecords();

				// Sync already-tracked representations into RecordState / relation state.
				$syncResult = $this->syncer->sync($context);
				$relationResult = $this->relationPlanner->plan($context);
				$flush = $this->scheduler->run(
					$records,
					$relationResult->getCommands(),
					true,
					$capture,
				);

				return new FlushResult(
					$syncResult->getSyncPlans(),
					$flush->getCommandResults(),
				);
			});
		} catch (Throwable $exception) {
			$this->restoreRecords($recordSnapshots);
			foreach ($representationSnapshots as [$state, $snapshot]) {
				$state->restoreBaselines($snapshot);
			}

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
		if ($capture !== null) {
			$this->history?->recordCommitted($capture->getChanges(), $label);
		}

		return $result;
	}

	/**
	 * @return list<array{0: RecordState, 1: array{
	 *     key: Key|null,
	 *     lifecycle: RecordLifecycle,
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
