<?php

declare(strict_types=1);

namespace ON\Data\ORM\State;

use ON\Data\ORM\Exception\StateException;

final class RecordHistory
{
	/** @var array<int, array<string, mixed>> */
	private array $snapshots = [];

	/**
	 * @param array<string, mixed> $values
	 */
	public function record(int $revision, array $values): void
	{
		$this->assertPositiveRevision($revision);
		if (array_key_exists($revision, $this->snapshots)) {
			throw new StateException(sprintf("Record history already contains revision %d.", $revision));
		}

		$this->snapshots[$revision] = $values;
		ksort($this->snapshots);
	}

	public function hasRevision(int $revision): bool
	{
		$this->assertPositiveRevision($revision);

		return array_key_exists($revision, $this->snapshots);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getSnapshot(int $revision): array
	{
		$this->assertPositiveRevision($revision);
		if (! array_key_exists($revision, $this->snapshots)) {
			throw new StateException(sprintf("Record history does not contain revision %d.", $revision));
		}

		return $this->snapshots[$revision];
	}

	public function hasValue(int $revision, string $field): bool
	{
		return array_key_exists($field, $this->getSnapshot($revision));
	}

	public function getValue(int $revision, string $field): mixed
	{
		$snapshot = $this->getSnapshot($revision);
		if (! array_key_exists($field, $snapshot)) {
			throw new StateException(sprintf("Record history revision %d does not contain field '%s'.", $revision, $field));
		}

		return $snapshot[$field];
	}

	/**
	 * @return list<int>
	 */
	public function getRevisions(): array
	{
		return array_keys($this->snapshots);
	}

	public function getOldestRevision(): int
	{
		if ($this->snapshots === []) {
			throw new StateException('Record history is empty.');
		}

		return min(array_keys($this->snapshots));
	}

	public function getLatestRevision(): int
	{
		if ($this->snapshots === []) {
			throw new StateException('Record history is empty.');
		}

		return max(array_keys($this->snapshots));
	}

	public function pruneBefore(int $minimumRevisionToKeep): void
	{
		$this->assertPositiveRevision($minimumRevisionToKeep);
		if ($this->snapshots === []) {
			return;
		}

		$latestRevision = $this->getLatestRevision();
		$keepFrom = min($minimumRevisionToKeep, $latestRevision);

		foreach (array_keys($this->snapshots) as $revision) {
			if ($revision < $keepFrom) {
				unset($this->snapshots[$revision]);
			}
		}
	}

	private function assertPositiveRevision(int $revision): void
	{
		if ($revision < 1) {
			throw new StateException(sprintf('Record revision must be a positive integer, %d given.', $revision));
		}
	}
}
