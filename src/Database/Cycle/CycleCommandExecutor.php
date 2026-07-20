<?php

declare(strict_types=1);

namespace ON\Data\Database\Cycle;

use Cycle\Database\DatabaseInterface;
use Cycle\Database\Query\QueryParameters;
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

		// InsertQuery::run() returns lastInsertID and discards rowCount; execute for affected rows.
		$parameters = new QueryParameters();
		$affected = $this->affectedRows(
			$this->database->getDriver()->execute(
				$insert->sqlStatement($parameters),
				$parameters->getParameters(),
			),
		);

		return new CommandResult($affected, $this->getGeneratedValueAfterInsert($command));
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
	 * @return array<string, mixed>
	 */
	private function getGeneratedValueAfterInsert(InsertCommand $command): array
	{
		$field = $this->getGeneratedPrimaryKeyField($command->getCollection());
		if ($field === null) {
			return [];
		}

		$fieldName = $field->getName();
		if (array_key_exists($fieldName, $command->getValues()) && $command->getValues()[$fieldName] !== null) {
			return [];
		}

		$generatedId = $this->normalizeGeneratedId($this->database->getDriver()->lastInsertID());
		if ($generatedId === null) {
			return [];
		}

		return [$fieldName => $generatedId];
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

	private function affectedRows(mixed $result): int
	{
		return is_int($result) && $result >= 0 ? $result : 0;
	}
}
