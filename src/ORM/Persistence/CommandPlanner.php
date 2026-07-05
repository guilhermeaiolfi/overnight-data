<?php

declare(strict_types=1);

namespace ON\Data\ORM\Persistence;

use ON\Data\ORM\Exception\InvalidCommandException;
use ON\Data\ORM\State\RecordState;

final class CommandPlanner
{
	public function plan(RecordState $record): ?CommandInterface
	{
		$record->resolveValueRefs();

		if ($record->isNew()) {
			$values = $record->getValues();

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

				return new DeleteCommand($record->getCollection(), $identity);
			}

			return null;
		}

		return null;
	}

	private function planDirty(RecordState $record): ?UpdateCommand
	{
		$changes = $record->getDirtyValues();

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

		return new UpdateCommand($record->getCollection(), $identity, $changes);
	}
}
