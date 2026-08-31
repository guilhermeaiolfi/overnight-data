<?php

declare(strict_types=1);

namespace ON\Data\Database\Cycle;

use Cycle\Database\DatabaseInterface;
use Cycle\Database\Driver\DriverInterface;
use Cycle\Database\Query\InsertQuery;
use Cycle\Database\Query\QueryParameters;
use Cycle\Database\Query\ReturningInterface;
use Cycle\Database\StatementInterface;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Field\FieldInterface;
use ON\Data\Definition\Field\Generator\When;
use ON\Data\ORM\Exception\InvalidCommandException;
use ON\Data\ORM\Persistence\CommandExecutorInterface;
use ON\Data\ORM\Persistence\CommandInterface;
use ON\Data\ORM\Persistence\CommandResult;
use ON\Data\ORM\Persistence\CommandValueResolver;
use ON\Data\ORM\Persistence\DeleteCommand;
use ON\Data\ORM\Persistence\InsertCommand;
use ON\Data\ORM\Persistence\TransactionalCommandExecutorInterface;
use ON\Data\ORM\Persistence\UpdateCommand;

final class CycleCommandExecutor implements CommandExecutorInterface, TransactionalCommandExecutorInterface
{
	public function __construct(
		private readonly DatabaseInterface $database,
		private ?CommandValueResolver $commandValueResolver = null,
	) {
		$this->commandValueResolver ??= new CommandValueResolver();
	}

	public function execute(CommandInterface $command): CommandResult
	{
		$this->commandValueResolver->assertReady($command);

		return match (true) {
			$command instanceof InsertCommand => $this->insert($command),
			$command instanceof UpdateCommand => $this->update($command),
			$command instanceof DeleteCommand => $this->delete($command),
			default => throw new InvalidCommandException(sprintf(
				"Unsupported persistence command '%s'.",
				$command::class,
			)),
		};
	}

	public function transaction(callable $callback): mixed
	{
		return $this->database->transaction(fn (): mixed => $callback());
	}

	private function insert(InsertCommand $command): CommandResult
	{
		$insert = $this->database
			->insert($this->getTable($command))
			->values($this->mapFieldValuesToColumns($command->getCollection(), $command->getValues()));

		$pending = $this->insertReadbackFields($command);
		if ($pending !== [] && $insert instanceof ReturningInterface) {
			return $this->insertWithReturning($insert, $pending);
		}

		// InsertQuery::run() returns lastInsertID and discards rowCount; execute for the write.
		$parameters = new QueryParameters();
		$driver = $this->database->getDriver(DatabaseInterface::WRITE);
		$affected = $this->affectedRows(
			$driver->execute(
				$insert->sqlStatement($parameters),
				$parameters->getParameters(),
			),
		);
		$generated = $this->generatedValuesViaLastInsertId($command, $pending, $driver);
		$remaining = $this->fieldsNotRecovered($pending, $generated);
		if ($remaining !== []) {
			$identity = $this->insertIdentityForReadback($command, $generated);
			if ($identity !== []) {
				$generated += $this->fetchFieldValues($command->getCollection(), $remaining, $identity);
			}
		}

		return new CommandResult($affected, $generated);
	}

	/**
	 * @param list<FieldInterface> $fields
	 */
	private function insertWithReturning(ReturningInterface $insert, array $fields): CommandResult
	{
		if (! $insert instanceof InsertQuery) {
			throw new InvalidCommandException(
				'RETURNING insert recovery requires a Cycle InsertQuery builder.',
			);
		}

		$columns = [];
		$columnToField = [];
		foreach ($fields as $field) {
			$column = $field->getColumn();
			$columns[] = $column;
			$columnToField[strtolower($column)] = $field->getName();
		}

		$insert->returning(...$columns);

		$parameters = new QueryParameters();
		$statement = $this->database->getDriver()->query(
			$insert->sqlStatement($parameters),
			$parameters->getParameters(),
		);

		$raw = null;
		$affected = 0;

		try {
			// Cycle's statement wrapper forces FETCH_ASSOC; fetchColumn() is unreliable.
			$raw = $statement->fetch(StatementInterface::FETCH_ASSOC);
			$affected = $this->affectedRows($statement->rowCount());
		} finally {
			$statement->close();
		}

		$generated = $this->mapReturningResult($raw, $fields, $columnToField);
		if ($affected === 0 && $generated !== []) {
			$affected = 1;
		}

		return new CommandResult($affected, $generated);
	}

	/**
	 * @param list<FieldInterface> $fields
	 * @param array<string, string> $columnToField lowercase column → field name
	 *
	 * @return array<string, mixed>
	 */
	private function mapReturningResult(mixed $raw, array $fields, array $columnToField): array
	{
		if ($fields === []) {
			return [];
		}

		if (count($fields) === 1 && ! is_array($raw)) {
			$value = $this->normalizeRecoveredValue($raw);

			return $value === null ? [] : [$fields[0]->getName() => $value];
		}

		if (! is_array($raw)) {
			return [];
		}

		$generated = [];
		foreach ($raw as $column => $value) {
			$fieldName = $columnToField[strtolower((string) $column)] ?? null;
			if ($fieldName === null) {
				continue;
			}

			$normalized = $this->normalizeRecoveredValue($value);
			if ($normalized !== null) {
				$generated[$fieldName] = $normalized;
			}
		}

		return $generated;
	}

	private function update(UpdateCommand $command): CommandResult
	{
		$collection = $command->getCollection();
		$query = $this->database
			->update($this->getTable($command))
			->values($this->mapFieldValuesToColumns($collection, $command->getChanges()));

		foreach ($this->mapFieldValuesToColumns($collection, $command->getIdentity()) as $column => $value) {
			$query->where($column, $value);
		}

		$affected = $this->affectedRows($query->run());
		$generated = [];
		$pending = $this->updateReadbackFields($command);
		if ($affected > 0 && $pending !== []) {
			$generated = $this->fetchFieldValues($collection, $pending, $command->getIdentity());
		}

		return new CommandResult($affected, $generated);
	}

	private function delete(DeleteCommand $command): CommandResult
	{
		$collection = $command->getCollection();
		$query = $this->database->delete($this->getTable($command));

		foreach ($this->mapFieldValuesToColumns($collection, $command->getIdentity()) as $column => $value) {
			$query->where($column, $value);
		}

		return new CommandResult($this->affectedRows($query->run()));
	}

	private function getTable(CommandInterface $command): string
	{
		return $command->getCollection()->getTable();
	}

	/**
	 * @param array<string, mixed> $values
	 * @return array<string, mixed>
	 */
	private function mapFieldValuesToColumns(CollectionInterface $collection, array $values): array
	{
		$mapped = [];
		foreach ($values as $fieldName => $value) {
			$mapped[$this->getColumnName($collection, (string) $fieldName)] = $value;
		}

		return $mapped;
	}

	private function getColumnName(CollectionInterface $collection, string $fieldName): string
	{
		$field = $collection->getField($fieldName);
		if ($field === null) {
			throw new InvalidCommandException(sprintf(
				"Persistence command for collection '%s' contains unknown field '%s'.",
				$collection->getName(),
				$fieldName,
			));
		}

		return $field->getColumn();
	}

	/**
	 * Collection fields omitted from the insert payload (DB default / identity / trigger).
	 *
	 * @return list<FieldInterface>
	 */
	private function insertReadbackFields(InsertCommand $command): array
	{
		$values = $command->getValues();
		$pending = [];

		foreach ($command->getCollection()->getFields() as $field) {
			if (array_key_exists($field->getName(), $values)) {
				continue;
			}

			$pending[] = $field;
		}

		return $pending;
	}

	/**
	 * Database-owned fields the adapter should reread after update.
	 *
	 * @return list<FieldInterface>
	 */
	private function updateReadbackFields(UpdateCommand $command): array
	{
		$pending = [];

		foreach ($command->getCollection()->getFields() as $field) {
			if (! $field->isDatabaseGenerated() || ! $field->isGeneratedWhen(When::UPDATE)) {
				continue;
			}

			$pending[] = $field;
		}

		return $pending;
	}

	/**
	 * @param list<FieldInterface> $pending
	 * @param array<string, mixed> $generated
	 *
	 * @return list<FieldInterface>
	 */
	private function fieldsNotRecovered(array $pending, array $generated): array
	{
		$remaining = [];
		foreach ($pending as $field) {
			if (! array_key_exists($field->getName(), $generated)) {
				$remaining[] = $field;
			}
		}

		return $remaining;
	}

	/**
	 * @param array<string, mixed> $generated
	 *
	 * @return array<string, mixed>
	 */
	private function insertIdentityForReadback(InsertCommand $command, array $generated): array
	{
		$collection = $command->getCollection();
		if (! $collection->hasPrimaryKey()) {
			return [];
		}

		$values = $command->getValues() + $generated;
		$identity = [];
		foreach ($collection->getPrimaryKey() as $fieldName) {
			if (! array_key_exists($fieldName, $values) || $values[$fieldName] === null) {
				return [];
			}

			$identity[$fieldName] = $values[$fieldName];
		}

		return $identity;
	}

	/**
	 * @param list<FieldInterface> $fields
	 * @param array<string, mixed> $identity
	 *
	 * @return array<string, mixed>
	 */
	private function fetchFieldValues(CollectionInterface $collection, array $fields, array $identity): array
	{
		if ($fields === [] || $identity === []) {
			return [];
		}

		$columns = [];
		$columnToField = [];
		foreach ($fields as $field) {
			$column = $field->getColumn();
			$columns[] = $column;
			$columnToField[strtolower($column)] = $field->getName();
		}

		$select = $this->database->select($columns)->from($collection->getTable());
		foreach ($this->mapFieldValuesToColumns($collection, $identity) as $column => $value) {
			$select->where($column, $value);
		}

		$row = $select->limit(1)->fetchAll(StatementInterface::FETCH_ASSOC)[0] ?? null;

		return $this->mapReturningResult($row, $fields, $columnToField);
	}

	/**
	 * @param list<FieldInterface> $pending
	 *
	 * @return array<string, mixed>
	 */
	private function generatedValuesViaLastInsertId(
		InsertCommand $command,
		array $pending,
		DriverInterface $driver,
	): array {
		$field = $this->getGeneratedPrimaryKeyField($command->getCollection());
		if ($field === null) {
			return [];
		}

		$pendingNames = [];
		foreach ($pending as $pendingField) {
			$pendingNames[$pendingField->getName()] = true;
		}

		if (! isset($pendingNames[$field->getName()])) {
			return [];
		}

		$generatedId = $this->normalizeGeneratedId(
			$driver->lastInsertID($field->getGeneratorSequence()),
		);
		if ($generatedId === null) {
			return [];
		}

		return [$field->getName() => $generatedId];
	}

	private function getGeneratedPrimaryKeyField(CollectionInterface $collection): ?FieldInterface
	{
		if (! $collection->hasPrimaryKey() || $collection->isCompositePrimaryKey()) {
			return null;
		}

		$field = $collection->getPrimaryKeyFields()[0];
		if (! $field->isDatabaseGenerated() || ! $field->isGeneratedWhen(When::INSERT)) {
			return null;
		}

		return $field;
	}

	private function normalizeGeneratedId(mixed $id): mixed
	{
		if ($id === false || $id === null || $id === '') {
			return null;
		}

		return $this->normalizeRecoveredValue($id);
	}

	private function normalizeRecoveredValue(mixed $value): mixed
	{
		if ($value === false) {
			return null;
		}

		if (is_string($value) && preg_match('/^[0-9]+$/', $value) === 1) {
			return (int) $value;
		}

		return $value;
	}

	private function affectedRows(mixed $result): int
	{
		return is_int($result) && $result >= 0 ? $result : 0;
	}
}
