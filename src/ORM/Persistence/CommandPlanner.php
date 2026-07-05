<?php

declare(strict_types=1);

namespace ON\Data\ORM\Persistence;

use ON\Data\ORM\Exception\InvalidCommandException;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\ValueRef;

final class CommandPlanner
{
	public function plan(RecordState $record): ?CommandInterface
	{
		$record->resolveValueRefs();

		if ($record->isNew()) {
			$values = $record->getValues();
			$this->assertNoUnresolvedValueRefs($record, $values, 'insert values');

			return new InsertCommand($record->getCollection(), $values);
		}

		if ($record->isClean()) {
			return null;
		}

		if ($record->isDirty()) {
			return $this->planDirty($record);
		}

		if ($record->isRemoved()) {
			$key = $record->getKey();

			if ($key !== null) {
				$identity = $key->getValues();
				$this->assertNoUnresolvedValueRefs($record, $identity, 'delete identity');

				return new DeleteCommand($record->getCollection(), $identity);
			}

			return null;
		}

		return null;
	}

	private function planDirty(RecordState $record): ?UpdateCommand
	{
		$changes = $record->getDirtyValues();
		$this->assertNoUnresolvedValueRefs($record, $changes, 'update changes');

		if ($changes === []) {
			return null;
		}

		$key = $record->getKey();
		if ($key === null) {
			throw new InvalidCommandException(sprintf(
				"Dirty record for collection '%s' cannot be planned without a key.",
				$record->getCollection()->getName(),
			));
		}

		$identity = $key->getValues();
		$this->assertNoUnresolvedValueRefs($record, $identity, 'update identity');

		return new UpdateCommand($record->getCollection(), $identity, $changes);
	}

	/**
	 * @param array<string, mixed> $values
	 */
	private function assertNoUnresolvedValueRefs(RecordState $record, array $values, string $slot): void
	{
		foreach ($values as $field => $value) {
			if (! $value instanceof ValueRef) {
				continue;
			}

			throw new InvalidCommandException(sprintf(
				"Cannot plan %s for collection '%s' record '%s' because field '%s' references unresolved value '%s.%s' on record '%s'.",
				$slot,
				$record->getCollectionName(),
				$record->getStateHash(),
				(string) $field,
				$value->getRecord()->getCollectionName(),
				$value->getField(),
				$value->getRecord()->getStateHash(),
			));
		}
	}
}
