<?php

declare(strict_types=1);

namespace ON\Data\ORM\Persistence;

use ON\Data\Key;
use ON\Data\ORM\Exception\InvalidCommandException;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RecordStateStore;
use ON\Data\ORM\State\ValueRef;

final class FlushScheduler
{
	private CommandPlanner $planner;
	private CommandValueResolver $commandValueResolver;

	public function __construct(
		private CommandExecutorInterface $executor,
		?CommandPlanner $planner = null,
		?CommandValueResolver $commandValueResolver = null,
	) {
		$this->planner = $planner ?? new CommandPlanner();
		$this->commandValueResolver = $commandValueResolver ?? new CommandValueResolver();
	}

	/**
	 * @param list<CommandInterface> $commands
	 */
	public function run(
		RecordStateStore $states,
		array $commands = [],
		bool $deferFinalizers = false,
	): DeferredFlushResult {
		$results = [];
		$finalizers = [];
		$snapshot = $states->getAll();
		$pendingRecords = [];
		$pendingCommands = $commands;

		foreach ($snapshot as $record) {
			$pendingRecords[$record->getStateHash()] = $record;
		}

		do {
			$progress = false;

			foreach ($pendingRecords as $stateHash => $record) {
				$progress = $record->resolveValueRefs() || $progress;

				if ($record->isClean()) {
					unset($pendingRecords[$stateHash]);
					$progress = true;

					continue;
				}

				if ($record->isRemoved() && $record->getKey() === null) {
					$this->finalize($finalizers, static function () use ($states, $record): void {
						$states->removeState($record);
					}, $deferFinalizers);
					unset($pendingRecords[$stateHash]);
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

					unset($pendingRecords[$stateHash]);
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
					unset($pendingRecords[$stateHash]);
					$progress = true;

					continue;
				}

				if ($command instanceof UpdateCommand) {
					$this->finalize($finalizers, function () use ($states, $record): void {
						$this->markCleanAndIndex($states, $record);
					}, $deferFinalizers);
					unset($pendingRecords[$stateHash]);
					$progress = true;

					continue;
				}

				if ($command instanceof DeleteCommand) {
					$this->finalize($finalizers, static function () use ($states, $record): void {
						$states->removeState($record);
					}, $deferFinalizers);
					unset($pendingRecords[$stateHash]);
					$progress = true;
				}
			}

			foreach ($pendingCommands as $index => $command) {
				$progress = $this->commandValueResolver->resolve($command) || $progress;

				if ($this->commandValueResolver->hasUnresolvedValueRefs($command)) {
					break;
				}

				$result = $this->executor->execute($command);
				$results[] = $result;

				unset($pendingCommands[$index]);
				$progress = true;
			}

			$pendingCommands = array_values($pendingCommands);
		} while ($progress && ($pendingRecords !== [] || $pendingCommands !== []));

		if ($pendingRecords !== []) {
			$this->throwBlockedByUnresolvedValueRef($pendingRecords);
		}

		if ($pendingCommands !== []) {
			$this->commandValueResolver->assertReady($pendingCommands[0]);
		}

		return new DeferredFlushResult($results, $finalizers);
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
