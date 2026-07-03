<?php

declare(strict_types=1);

namespace ON\Data\ORM\Persistence;

use ON\Data\Key;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RecordStateMap;

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
	public function flush(RecordStateMap $states): array
	{
		$results = [];
		$snapshot = $states->getAll();

		foreach ($snapshot as $record) {
			$command = $this->planner->plan($record);

			if ($command === null) {
				if ($record->isRemoved()) {
					$states->removeState($record);
				}

				continue;
			}

			$result = $this->executor->execute($command);
			$results[] = $result;

			if ($command instanceof InsertCommand) {
				$this->syncInsertedRecord($states, $record, $result);

				continue;
			}

			if ($command instanceof UpdateCommand) {
				$record->markClean($record->getKey());
				if ($record->hasKey()) {
					$states->indexKey($record);
				}

				continue;
			}

			if ($command instanceof DeleteCommand) {
				$states->removeState($record);
			}
		}

		return $results;
	}

	private function syncInsertedRecord(RecordStateMap $states, RecordState $record, CommandResult $result): void
	{
		$generatedValues = $result->getGeneratedValues();
		if ($generatedValues !== []) {
			$record->setValues($generatedValues);
		}

		$key = $this->getKeyIfComplete($record);
		$record->markClean($key);

		if ($record->hasKey()) {
			$states->indexKey($record);
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
}
