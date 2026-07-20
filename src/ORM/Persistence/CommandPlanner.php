<?php

declare(strict_types=1);

namespace ON\Data\ORM\Persistence;

use ON\Data\Definition\Field\Generator\When;
use ON\Data\ORM\Exception\InvalidCommandException;
use ON\Data\ORM\Record\RecordState;

final class CommandPlanner
{
	public function __construct(
		private readonly FieldGeneratorApplier $fieldGenerators = new FieldGeneratorApplier(),
	) {
	}

	public function plan(RecordState $record): ?CommandInterface
	{
		$record->resolveValueRefs();

		if ($record->isNew()) {
			$this->fieldGenerators->apply($record, When::INSERT);
			$values = $this->valuesForInsert($record);

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

	/**
	 * @return array<string, mixed>
	 */
	private function valuesForInsert(RecordState $record): array
	{
		$values = $record->getValues();
		$collection = $record->getCollection();

		foreach ($collection->getFields() as $field) {
			if (! $field->isDatabaseGenerated() || ! $field->isGeneratedWhen(When::INSERT)) {
				continue;
			}

			$fieldName = $field->getName();
			if (array_key_exists($fieldName, $values) && $values[$fieldName] === null) {
				unset($values[$fieldName]);
			}
		}

		return $values;
	}

	private function planDirty(RecordState $record): ?UpdateCommand
	{
		$this->fieldGenerators->apply($record, When::UPDATE);
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
