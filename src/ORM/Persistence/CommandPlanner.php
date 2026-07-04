<?php

declare(strict_types=1);

namespace ON\Data\ORM\Persistence;

use ON\Data\ORM\Exception\InvalidCommandException;
use ON\Data\ORM\State\RecordState;

final class CommandPlanner
{
	public function plan(RecordState $record): ?CommandInterface
	{
		if ($record->isNew()) {
			return new InsertCommand($record->getCollection(), $record->getValues());
		}

		if ($record->isClean()) {
			return null;
		}

		if ($record->isDirty()) {
			return $this->planDirty($record);
		}

		if ($record->isRemoved()) {
			$key = $record->getKey();

			return $key === null
				? null
				: new DeleteCommand($record->getCollection(), $key->getValues());
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
				$record->getCollectionName(),
			));
		}

		return new UpdateCommand($record->getCollection(), $key->getValues(), $changes);
	}
}
