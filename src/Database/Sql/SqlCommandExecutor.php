<?php

declare(strict_types=1);

namespace ON\Data\Database\Sql;

use ON\Data\Database\ConnectionInterface;
use ON\Data\Database\DatabaseFamily;
use ON\Data\Database\Sql\Dialect\DialectFactory;
use ON\Data\Database\Sql\Dialect\DialectInterface;
use ON\Data\Database\TransactionStateAwareInterface;
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

final class SqlCommandExecutor implements CommandExecutorInterface, TransactionalCommandExecutorInterface, TransactionStateAwareInterface
{
	private readonly DialectInterface $dialect;

	private readonly SqlCommandCompiler $compiler;

	public function __construct(
		private readonly ConnectionInterface $connection,
		?CommandValueResolver $commandValueResolver = null,
		?DialectInterface $dialect = null,
	) {
		$this->dialect = $dialect ?? DialectFactory::forConnection($connection);
		$this->compiler = new SqlCommandCompiler($this->dialect, $connection->tablePrefix(), $commandValueResolver ?? new CommandValueResolver());
	}

	public function execute(CommandInterface $command): CommandResult
	{
		return match (true) {
			$command instanceof InsertCommand => $this->insert($command),
			$command instanceof UpdateCommand => $this->update($command),
			$command instanceof DeleteCommand => new CommandResult($this->executeStatement($this->compiler->delete($command))),
			default => throw new InvalidCommandException(sprintf(
				"Unsupported persistence command '%s'.",
				$command::class,
			)),
		};
	}

	public function isInTransaction(): ?bool
	{
		return $this->connection instanceof TransactionStateAwareInterface ? $this->connection->isInTransaction() : null;
	}

	public function transaction(callable $callback): mixed
	{
		return $this->connection->transaction($callback);
	}

	private function insert(InsertCommand $command): CommandResult
	{
		$pending = $this->insertReadbackFields($command);
		if ($pending !== [] && $this->dialect->supportsInsertReturning()) {
			$stmt = $this->compiler->insert($command, $pending);
			$row = $this->connection->fetchOne($stmt->sql, $stmt->params);

			return new CommandResult($row === null ? $this->sqliteChanges() : 1, $this->mapReadback($row, $pending));
		}

		$generatedKey = $this->generatedPrimaryKey($command);
		if ($generatedKey !== null && $this->dialect->family() === DatabaseFamily::SqlServer) {
			throw new InvalidCommandException('SQL Server requires OUTPUT to recover a generated primary key safely.');
		}

		$affected = $this->executeStatement($this->compiler->insert($command, []));
		$generated = $generatedKey !== null && ($affected > 0 || $this->dialect->sqliteChangesQuery() !== null)
			? $this->generatedValuesViaLastInsertId($generatedKey)
			: [];
		if ($affected === 0) {
			$affected = $this->sqliteChanges();
		}

		if ($affected === 0) {
			return new CommandResult(0);
		}

		$remaining = array_values(array_filter(
			$pending,
			static fn (FieldInterface $field): bool => ! array_key_exists($field->getName(), $generated),
		));
		if ($remaining === []) {
			return new CommandResult($affected, $generated);
		}

		$identity = $this->insertIdentityForReadback($command, $generated);
		if ($identity !== []) {
			$generated += $this->fetchFieldValues($command->getCollection(), $remaining, $identity);
		}

		return new CommandResult($affected, $generated);
	}

	private function update(UpdateCommand $command): CommandResult
	{
		$affected = $this->executeStatement($this->compiler->update($command));
		$pending = $this->updateReadbackFields($command);
		$generated = $affected > 0 && $pending !== []
			? $this->fetchFieldValues($command->getCollection(), $pending, $command->getIdentity())
			: [];

		return new CommandResult($affected, $generated);
	}

	private function executeStatement(SqlFragment $stmt): int
	{
		return max(0, $this->connection->execute($stmt->sql, $stmt->params));
	}

	private function sqliteChanges(): int
	{
		$sql = $this->dialect->sqliteChangesQuery();

		return $sql === null ? 0 : max(0, (int) ($this->connection->fetchOne($sql)['affected'] ?? 0));
	}

	/** @return list<FieldInterface> */
	private function insertReadbackFields(InsertCommand $command): array
	{
		$values = $command->getValues();
		$pending = [];
		foreach ($command->getCollection()->getFields() as $field) {
			if (! array_key_exists($field->getName(), $values)) {
				$pending[] = $field;
			}
		}

		return $pending;
	}

	/** @return list<FieldInterface> */
	private function updateReadbackFields(UpdateCommand $command): array
	{
		$pending = [];
		foreach ($command->getCollection()->getFields() as $field) {
			if ($field->isDatabaseGenerated() && $field->isGeneratedWhen(When::UPDATE)) {
				$pending[] = $field;
			}
		}

		return $pending;
	}

	/**
	 * @param array<string, mixed> $generated
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
			if (! isset($values[$fieldName])) {
				return [];
			}

			$identity[$fieldName] = $values[$fieldName];
		}

		return $identity;
	}

	/**
	 * @param non-empty-list<FieldInterface> $fields
	 * @param array<string, mixed> $identity
	 * @return array<string, mixed>
	 */
	private function fetchFieldValues(CollectionInterface $collection, array $fields, array $identity): array
	{
		$stmt = $this->compiler->selectReadback($collection, $fields, $identity);

		return $this->mapReadback($this->connection->fetchOne($stmt->sql, $stmt->params), $fields);
	}

	/**
	 * @param array<string, mixed>|null $row
	 * @param list<FieldInterface> $fields
	 * @return array<string, mixed>
	 */
	private function mapReadback(?array $row, array $fields): array
	{
		if ($row === null) {
			return [];
		}

		$generated = [];
		$folded = array_change_key_case($row, CASE_LOWER);
		foreach ($fields as $field) {
			$column = $field->getColumn();
			$foldedColumn = strtolower($column);
			if (array_key_exists($column, $row)) {
				$generated[$field->getName()] = $row[$column];
			} elseif (array_key_exists($foldedColumn, $folded)) {
				$generated[$field->getName()] = $folded[$foldedColumn];
			}
		}

		return $generated;
	}

	private function generatedPrimaryKey(InsertCommand $command): ?FieldInterface
	{
		$collection = $command->getCollection();
		if (! $collection->hasPrimaryKey() || $collection->isCompositePrimaryKey()) {
			return null;
		}

		$field = $collection->getPrimaryKeyFields()[0];

		return ! array_key_exists($field->getName(), $command->getValues())
			&& $field->isDatabaseGenerated()
			&& $field->isGeneratedWhen(When::INSERT)
			? $field
			: null;
	}

	/** @return array<string, mixed> */
	private function generatedValuesViaLastInsertId(FieldInterface $field): array
	{
		$id = $this->connection->lastInsertId($field->getGeneratorSequence());
		if ($id === false || $id === '') {
			return [];
		}

		if ($id === '0' && in_array($this->dialect->family(), [DatabaseFamily::MySQL, DatabaseFamily::MariaDB], true)) {
			return [];
		}

		$integer = filter_var($id, FILTER_VALIDATE_INT);

		return [$field->getName() => $integer === false ? $id : $integer];
	}
}
