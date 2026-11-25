<?php

declare(strict_types=1);

namespace ON\Data\ORM\History;

use ON\Data\ORM\Exception\NonTransactionalFlushException;
use ON\Data\ORM\Persistence\AffectedRowsValidator;
use ON\Data\ORM\Persistence\CommandExecutorInterface;
use ON\Data\ORM\Persistence\TransactionalCommandExecutorInterface;

final readonly class FlushReverter
{
	private TransactionalCommandExecutorInterface $executor;

	public function __construct(private FlushHistory $history, CommandExecutorInterface $executor)
	{
		if (! $executor instanceof TransactionalCommandExecutorInterface) {
			throw new NonTransactionalFlushException(sprintf(
				"Revert requires a command executor that implements %s; '%s' does not.",
				TransactionalCommandExecutorInterface::class,
				$executor::class,
			));
		}
		$this->executor = $executor;
	}

	public function revertLast(): FlushCommit
	{
		$this->history->beginWrite();

		try {
			if ($this->executor->isInTransaction() === true) {
				throw HistoryException::activeTransaction();
			}

			$latest = $this->history->latest() ?? throw new HistoryRollbackException('Cannot revert an empty flush history.');
			$this->revert([$latest], $latest->sequence - 1);

			return $latest;
		} finally {
			$this->history->endWrite();
		}
	}

	/** @return list<FlushCommit> The undone commits, newest first. */
	public function revertTo(int $sequence): array
	{
		$this->history->beginWrite();

		try {
			if ($this->executor->isInTransaction() === true) {
				throw HistoryException::activeTransaction();
			}

			if ($sequence !== $this->history->getBaseSequence() && $this->history->get($sequence) === null) {
				throw new HistoryRollbackException(sprintf('Sequence %d is not retained in flush history.', $sequence));
			}

			$commits = array_reverse($this->history->since($sequence));
			if ($commits !== []) {
				$this->revert($commits, $sequence);
			}

			return $commits;
		} finally {
			$this->history->endWrite();
		}
	}

	/** @param non-empty-list<FlushCommit> $commits Newest first. */
	private function revert(array $commits, int $sequence): void
	{
		$commands = [];
		foreach ($commits as $commit) {
			foreach (array_reverse($commit->changes) as $change) {
				try {
					$inverse = $change->inverse();
				} catch (HistoryRollbackException $exception) {
					throw new HistoryRollbackException(sprintf('Commit %d: %s', $commit->sequence, $exception->getMessage()), previous: $exception);
				}
				if ($inverse !== null) {
					$commands[] = $inverse;
				}
			}
		}

		$this->executor->transaction(function () use ($commands): void {
			$validator = new AffectedRowsValidator();
			foreach ($commands as $command) {
				$validator->validate($command, $this->executor->execute($command));
			}
		});
		$this->history->discardAfter($sequence);
	}
}
