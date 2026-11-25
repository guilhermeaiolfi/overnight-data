<?php

declare(strict_types=1);

namespace ON\Data\ORM\History;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Field\Generator\When;
use ON\Data\ORM\Persistence\CommandInterface;
use ON\Data\ORM\Persistence\CommandResult;
use ON\Data\ORM\Persistence\DeleteCommand;
use ON\Data\ORM\Persistence\InsertCommand;
use ON\Data\ORM\Persistence\UpdateCommand;
use ON\Data\ORM\Record\RecordState;

/** @internal Stages detached changes until the enclosing flush has committed. */
final class FlushCapture
{
	/** @var list<FlushChange> */
	private array $changes = [];

	public function record(CommandInterface $command, CommandResult $result, ?RecordState $record = null): void
	{
		$collection = $command->getCollection();
		$source = $record === null ? FlushChangeSource::Extra : FlushChangeSource::Record;
		$original = $record?->getOriginalValues() ?? [];
		$identity = $before = $after = [];
		$operation = FlushChangeOperation::Custom;
		if ($command instanceof InsertCommand) {
			$operation = FlushChangeOperation::Insert;
			$after = array_replace($command->getValues(), $result->getGeneratedValues());
			$identity = $collection->hasPrimaryKey()
				? array_intersect_key($after, array_flip($collection->getPrimaryKey()))
				: ($record === null ? $after : []);
		} elseif ($command instanceof UpdateCommand) {
			$operation = FlushChangeOperation::Update;
			$identity = $command->getIdentity();
			$after = array_replace($command->getChanges(), $result->getGeneratedValues());
			$before = array_intersect_key($original, $after);
		} elseif ($command instanceof DeleteCommand) {
			$operation = FlushChangeOperation::Delete;
			$identity = $command->getIdentity();
			$before = $result->getAffectedRows() === 0 ? [] : $identity + $original;
		} else {
			$data = $command instanceof InspectableCommandInterface ? $command->getHistoryData() : [];
			$identity = $data['identity'] ?? [];
			$after = array_replace($data['values'] ?? [], $result->getGeneratedValues());
		}

		$changedFields = $result->getAffectedRows() === 0 ? [] : array_keys($operation === FlushChangeOperation::Delete ? $before : $after);
		[$identity, $missingIdentity] = HistoryValue::snapshot($identity);
		[$before, $missingBefore] = HistoryValue::snapshot($before);
		[$after, $missingAfter] = HistoryValue::snapshot($after);
		$unavailable = array_filter(['identity' => $missingIdentity, 'before' => $missingBefore, 'after' => $missingAfter]);

		$this->changes[] = new FlushChange(
			$collection,
			$operation,
			$source,
			$command::class,
			$result->getAffectedRows(),
			$identity,
			$before,
			$after,
			$changedFields,
			$unavailable,
			$this->irreversibleReason(
				$collection,
				$operation,
				$source,
				$result->getAffectedRows(),
				$identity,
				$before,
				$after,
				$unavailable,
			),
		);
	}

	/** @return list<FlushChange> */
	public function getChanges(): array
	{
		return $this->changes;
	}

	/**
	 * @param array<string, mixed> $identity
	 * @param array<string, mixed> $before
	 * @param array<string, mixed> $after
	 * @param array<string, list<string>> $unavailable
	 */
	private function irreversibleReason(
		CollectionInterface $collection,
		FlushChangeOperation $operation,
		FlushChangeSource $source,
		int $affectedRows,
		array $identity,
		array $before,
		array $after,
		array $unavailable,
	): ?string {
		if ($operation === FlushChangeOperation::Custom) {
			return 'The command type does not expose a supported inverse.';
		}
		if ($affectedRows === 0) {
			return null;
		}
		if ($affectedRows !== 1) {
			return 'The command affected more than one row.';
		}
		if ($unavailable !== []) {
			return 'One or more command values could not be safely snapshotted.';
		}

		$fields = array_flip($collection->getFields()->getNames());
		if ($collection->hasPrimaryKey()) {
			foreach ($collection->getPrimaryKey() as $field) {
				if (! is_scalar($identity[$field] ?? null)) {
					return 'The resolved primary key is incomplete.';
				}
			}
		} elseif ($source === FlushChangeSource::Record || $operation === FlushChangeOperation::Update || $identity === [] || array_diff_key($fields, $identity) !== []) {
			return 'The command has no resolved primary key or complete extra-row identity.';
		}

		if ($operation === FlushChangeOperation::Delete && array_diff_key($fields, $before) !== []) {
			return 'A complete prior row snapshot is required to restore a delete.';
		}
		if ($operation === FlushChangeOperation::Update) {
			$missing = array_diff_key($after, $before);
			if ($missing !== []) {
				return 'Missing prior values for updated fields: ' . implode(', ', array_keys($missing)) . '.';
			}
			if (array_intersect_key($identity, $after) !== []) {
				return 'The update changed or regenerated an identity field.';
			}
			foreach ($collection->getFields() as $field) {
				if ($field->isDatabaseGenerated() && $field->isGeneratedWhen(When::UPDATE)) {
					return 'An update may regenerate database-managed fields during compensation.';
				}
			}
		}

		return null;
	}
}
