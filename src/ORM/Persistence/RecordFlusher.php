<?php

declare(strict_types=1);

namespace ON\Data\ORM\Persistence;

use ON\Data\Key;
use ON\Data\ORM\Exception\InvalidCommandException;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RecordStateStore;
use ON\Data\ORM\State\ValueRef;

final class RecordFlusher
{
	public function __construct(
		private CommandExecutorInterface $executor,
		private ?CommandPlanner $planner = null,
	) {
		$this->planner ??= new CommandPlanner();
	}

	/**
	 * @return list<CommandResult>
	 */
	public function flush(RecordStateStore $states): array
	{
		return $this->flushRecords($states, false)->getCommandResults();
	}

	public function flushDeferred(RecordStateStore $states): DeferredRecordFlushResult
	{
		return $this->flushRecords($states, true);
	}

	private function flushRecords(RecordStateStore $states, bool $deferFinalizers): DeferredRecordFlushResult
	{
		$results = [];
		$finalizers = [];
		$snapshot = $states->getAll();
		$pending = [];

		foreach ($snapshot as $record) {
			$pending[$record->getStateHash()] = $record;
		}

		do {
			$progress = false;

			foreach ($pending as $stateHash => $record) {
				$progress = $record->resolveValueRefs() || $progress;

				if ($record->isClean()) {
					unset($pending[$stateHash]);
					$progress = true;

					continue;
				}

				if ($record->isRemoved() && $record->getKey() === null) {
					$this->finalize($finalizers, static function () use ($states, $record): void {
						$states->removeState($record);
					}, $deferFinalizers);
					unset($pending[$stateHash]);
					$progress = true;

					continue;
				}

				if (! $this->isReadyForPlanning($record)) {
					continue;
				}

				$command = $this->planner->plan($record);

				if ($command === null) {
					if ($record->isRemoved()) {
						$this->finalize($finalizers, static function () use ($states, $record): void {
							$states->removeState($record);
						}, $deferFinalizers);
					}

					unset($pending[$stateHash]);
					$progress = true;

					continue;
				}

				$result = $this->executor->execute($command);
				$results[] = $result;

				if ($command instanceof InsertCommand) {
					$this->syncInsertedRecord($record, $result);
					if ($deferFinalizers) {
						$finalizers[] = function () use ($states, $record): void {
							$this->markCleanAndIndex($states, $record);
						};
					} else {
						$this->markCleanAndIndex($states, $record);
					}
					unset($pending[$stateHash]);
					$progress = true;

					continue;
				}

				if ($command instanceof UpdateCommand) {
					$this->finalize($finalizers, function () use ($states, $record): void {
						$this->markCleanAndIndex($states, $record);
					}, $deferFinalizers);
					unset($pending[$stateHash]);
					$progress = true;

					continue;
				}

				if ($command instanceof DeleteCommand) {
					$this->finalize($finalizers, static function () use ($states, $record): void {
						$states->removeState($record);
					}, $deferFinalizers);
					unset($pending[$stateHash]);
					$progress = true;
				}
			}
		} while ($progress && $pending !== []);

		if ($pending !== []) {
			$this->throwBlockedByUnresolvedValueRef($pending);
		}

		return new DeferredRecordFlushResult($results, $finalizers);
	}

	private function isReadyForPlanning(RecordState $record): bool
	{
		if ($record->isNew()) {
			return $this->getUnresolvedValueRefsIn($record->getValues()) === [];
		}

		if ($record->isDirty()) {
			return $this->getUnresolvedValueRefsIn($record->getDirtyValues()) === []
				&& $this->getUnresolvedValueRefsIn($this->getIdentityValues($record)) === [];
		}

		if ($record->isRemoved()) {
			return $this->getUnresolvedValueRefsIn($this->getIdentityValues($record)) === [];
		}

		return true;
	}

	/**
	 * @param array<string, mixed> $values
	 *
	 * @return array<string, ValueRef>
	 */
	private function getUnresolvedValueRefsIn(array $values): array
	{
		$unresolved = [];
		foreach ($values as $field => $value) {
			if ($value instanceof ValueRef && ! $value->isResolved()) {
				$unresolved[(string) $field] = $value;
			}
		}

		return $unresolved;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function getIdentityValues(RecordState $record): array
	{
		$key = $record->getKey();

		return $key instanceof Key ? $key->getValues() : [];
	}

	/**
	 * @param array<string, RecordState> $pending
	 */
	private function throwBlockedByUnresolvedValueRef(array $pending): never
	{
		foreach ($pending as $record) {
			$unresolved = $this->getBlockingValueRefs($record);
			if ($unresolved === []) {
				$unresolved = $record->getUnresolvedValueRefs();
			}

			foreach ($unresolved as $field => $ref) {
				throw new InvalidCommandException(sprintf(
					"Cannot flush collection '%s' record '%s' because field '%s' references unresolved value '%s.%s' on record '%s'.",
					$record->getCollection()->getName(),
					$record->getStateHash(),
					(string) $field,
					$ref->getRecord()->getCollection()->getName(),
					$ref->getField(),
					$ref->getRecord()->getStateHash(),
				));
			}
		}

		throw new InvalidCommandException('Cannot flush records because pending records made no progress.');
	}

	/**
	 * @return array<string, ValueRef>
	 */
	private function getBlockingValueRefs(RecordState $record): array
	{
		if ($record->isNew()) {
			return $this->getUnresolvedValueRefsIn($record->getValues());
		}

		if ($record->isDirty()) {
			return $this->getUnresolvedValueRefsIn($record->getDirtyValues())
				+ $this->getUnresolvedValueRefsIn($this->getIdentityValues($record));
		}

		if ($record->isRemoved()) {
			return $this->getUnresolvedValueRefsIn($this->getIdentityValues($record));
		}

		return [];
	}

	private function syncInsertedRecord(RecordState $record, CommandResult $result): void
	{
		$generatedValues = $result->getGeneratedValues();
		if ($generatedValues !== []) {
			$record->setValues($generatedValues);
		}
	}

	private function getKeyIfComplete(RecordState $record): ?Key
	{
		$collection = $record->getCollection();
		if (! $collection->hasPrimaryKey()) {
			return null;
		}

		$values = $record->getValues();
		foreach ($collection->getPrimaryKey() as $fieldName) {
			if (! array_key_exists($fieldName, $values)) {
				return null;
			}
		}

		return $collection->getKeyFromRecord($values);
	}

	/**
	 * @param list<callable(): void> $finalizers
	 * @param callable(): void $finalizer
	 */
	private function finalize(array &$finalizers, callable $finalizer, bool $defer): void
	{
		if ($defer) {
			$finalizers[] = $finalizer;

			return;
		}

		$finalizer();
	}

	private function markCleanAndIndex(RecordStateStore $states, RecordState $record): void
	{
		$record->markClean($this->getKeyIfComplete($record) ?? $record->getKey());
		if ($record->hasKey()) {
			$states->indexKey($record);
		}
	}
}
