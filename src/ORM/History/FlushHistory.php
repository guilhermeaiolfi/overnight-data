<?php

declare(strict_types=1);

namespace ON\Data\ORM\History;

use Closure;
use Countable;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class FlushHistory implements Countable
{
	/** @var array<int, FlushCommit> Oldest to newest, keyed by sequence for direct lookup. */
	private array $entries = [];
	private int $sequence = 0;
	private int $baseSequence = 0;
	private bool $writing = false;
	/** @var array<int, Closure(FlushCommit): void> */
	private array $commitListeners = [];
	/** @var array<int, Closure(list<FlushCommit>, int): void> */
	private array $revertListeners = [];

	public function __construct(private readonly int $maxEntries = 100)
	{
		if ($maxEntries <= 0) {
			throw new InvalidArgumentException('Flush history maxEntries must be positive.');
		}
	}

	/** @return list<FlushCommit> */
	public function all(): array
	{
		return array_values($this->entries);
	}

	public function latest(): ?FlushCommit
	{
		$sequence = array_key_last($this->entries);

		return $sequence === null ? null : $this->entries[$sequence];
	}

	public function get(int $sequence): ?FlushCommit
	{
		return $this->entries[$sequence] ?? null;
	}

	/** @return list<FlushCommit> Entries strictly newer than the supplied sequence. */
	public function since(int $sequence): array
	{
		return array_values(array_filter($this->entries, static fn (FlushCommit $commit): bool => $commit->sequence > $sequence));
	}

	public function count(): int
	{
		return count($this->entries);
	}

	public function isEmpty(): bool
	{
		return $this->entries === [];
	}

	public function clear(): void
	{
		$this->assertIdle();
		$this->entries = [];
		$this->baseSequence = $this->sequence;
	}

	public function getMaxEntries(): int
	{
		return $this->maxEntries;
	}

	public function getBaseSequence(): int
	{
		return $this->baseSequence;
	}

	/**
	 * @param callable(FlushCommit): void $listener
	 * @return Closure(): void
	 */
	public function onCommit(callable $listener): Closure
	{
		$this->commitListeners[] = Closure::fromCallable($listener);
		$id = array_key_last($this->commitListeners);

		return function () use ($id): void {
			unset($this->commitListeners[$id]);
		};
	}

	/**
	 * @param callable(list<FlushCommit>, int): void $listener Receives undone commits newest first and the sequence reached.
	 * @return Closure(): void
	 */
	public function onRevert(callable $listener): Closure
	{
		$this->revertListeners[] = Closure::fromCallable($listener);
		$id = array_key_last($this->revertListeners);

		return function () use ($id): void {
			unset($this->revertListeners[$id]);
		};
	}

	/** @return list<array{sequence: int, label: string|array{base64: string}|null, committedAt: string, reversible: bool, changes: list<array<string, mixed>>}> */
	public function toArray(): array
	{
		return array_map(static fn (FlushCommit $commit): array => $commit->toArray(), $this->all());
	}

	/** @internal Reserve this journal for a flush or revert, including listener notification. */
	public function beginWrite(): void
	{
		$this->assertIdle();
		$this->writing = true;
	}

	/** @internal Release a successfully acquired journal in a finally block. */
	public function endWrite(): void
	{
		$this->writing = false;
	}

	/**
	 * @internal Called only after the flush transaction commits.
	 * @param list<FlushChange> $changes
	 */
	public function recordCommitted(array $changes, ?string $label): void
	{
		if ($changes === []) {
			return;
		}

		$commit = new FlushCommit(++$this->sequence, $label, $changes, new DateTimeImmutable('now', new DateTimeZone('UTC')));
		$this->entries[$commit->sequence] = $commit;
		if (count($this->entries) > $this->maxEntries) {
			$this->baseSequence = array_key_first($this->entries);
			unset($this->entries[$this->baseSequence]);
		}

		foreach ($this->commitListeners as $id => $listener) {
			if (isset($this->commitListeners[$id])) {
				$listener($commit);
			}
		}
	}

	/** @internal Called only after the compensating transaction commits. */
	public function discardAfter(int $sequence): void
	{
		$undone = [];
		while (($latest = array_key_last($this->entries)) !== null && $latest > $sequence) {
			$undone[] = $this->entries[$latest];
			unset($this->entries[$latest]);
		}
		if ($undone === []) {
			return;
		}

		$target = $this->latest()?->sequence ?? $this->baseSequence;
		foreach ($this->revertListeners as $id => $listener) {
			if (isset($this->revertListeners[$id])) {
				$listener($undone, $target);
			}
		}
	}

	private function assertIdle(): void
	{
		if ($this->writing) {
			throw new HistoryException('A flush or revert is already in progress for this history.');
		}
	}
}
