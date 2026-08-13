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

		$pending = $this->pendingDatabaseGeneratedFields($command);
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

		// Read lastInsertID before any follow-up query. PDO SQLite clears it on the next statement.
		$generated = $this->generatedValuesViaLastInsertId($command, $pending, $driver);

		$changes = $this->sqliteChangesAfterWrite($driver);
		if ($changes > 0) {
			$affected = $changes;
		}

		if ($affected === 0 && $this->generatedKeyImpliesInsert($generated)) {
			$affected = 1;
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

		try {
			$raw = count($columns) === 1
				? $statement->fetchColumn()
				: $statement->fetch(StatementInterface::FETCH_ASSOC);
			$affected = $this->affectedRows($statement->rowCount());
		} finally {
			$statement->close();
		}

		return new CommandResult($affected, $this->mapReturningResult($raw, $fields, $columnToField));
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
			$value = $this->normalizeGeneratedId($raw);

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

			$normalized = $this->normalizeGeneratedId($value);
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

		return new CommandResult($this->affectedRows($query->run()));
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
	 * DB-generated insert fields the command did not supply (database must fill them).
	 *
	 * @return list<FieldInterface>
	 */
	private function pendingDatabaseGeneratedFields(InsertCommand $command): array
	{
		$values = $command->getValues();
		$pending = [];

		foreach ($command->getCollection()->getFields() as $field) {
			if (! $field->isDatabaseGenerated() || ! $field->isGeneratedWhen(When::INSERT)) {
				continue;
			}

			$fieldName = $field->getName();
			if (array_key_exists($fieldName, $values) && $values[$fieldName] !== null) {
				continue;
			}

			$pending[] = $field;
		}

		return $pending;
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

		if (is_string($id) && preg_match('/^[0-9]+$/', $id) === 1) {
			return (int) $id;
		}

		return $id;
	}

	/**
	 * PDO SQLite often reports insert rowCount as 0 even when the row was written.
	 * `CHANGES()` is the connection-local count of the last write.
	 *
	 * Cycle's statement wrapper forces FETCH_ASSOC; fetchColumn() is unreliable on
	 * that mode, so read a numeric row instead.
	 */
	private function sqliteChangesAfterWrite(DriverInterface $driver): int
	{
		if (stripos($driver->getType(), 'sqlite') === false) {
			return 0;
		}

		$statement = $driver->query('SELECT CHANGES() AS affected');

		try {
			$row = $statement->fetch(StatementInterface::FETCH_NUM);
			$raw = is_array($row) && array_key_exists(0, $row) ? $row[0] : null;

			return $this->parseNonNegativeCount($raw);
		} finally {
			$statement->close();
		}
	}

	/**
	 * @param array<string, mixed> $generated
	 */
	private function generatedKeyImpliesInsert(array $generated): bool
	{
		foreach ($generated as $value) {
			if (is_int($value) && $value > 0) {
				return true;
			}

			if (is_string($value) && $value !== '' && $value !== '0') {
				return true;
			}
		}

		return false;
	}

	private function parseNonNegativeCount(mixed $raw): int
	{
		if (is_int($raw) && $raw >= 0) {
			return $raw;
		}

		if (is_float($raw) && $raw >= 0.0 && $raw === floor($raw)) {
			return (int) $raw;
		}

		if (is_string($raw) && preg_match('/^[0-9]+(?:\.0+)?$/', $raw) === 1) {
			return (int) $raw;
		}

		return 0;
	}

	private function affectedRows(mixed $result): int
	{
		return is_int($result) && $result >= 0 ? $result : 0;
	}
}
