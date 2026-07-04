<?php

declare(strict_types=1);

namespace ON\Data\Database\Cycle;

use Cycle\Database\DatabaseInterface;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\ORM\Exception\InvalidCommandException;
use ON\Data\ORM\Persistence\CommandExecutorInterface;
use ON\Data\ORM\Persistence\CommandInterface;
use ON\Data\ORM\Persistence\CommandResult;
use ON\Data\ORM\Persistence\DeleteCommand;
use ON\Data\ORM\Persistence\InsertCommand;
use ON\Data\ORM\Persistence\UpdateCommand;

final class CycleCommandExecutor implements CommandExecutorInterface
{
	public function __construct(
		private readonly DatabaseInterface $database,
	) {
	}

	public function execute(CommandInterface $command): CommandResult
	{
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

	private function insert(InsertCommand $command): CommandResult
	{
		$this->database
			->insert($this->getTable($command))
			->values($this->mapFieldValuesToColumns($command->getCollection(), $command->getValues()))
			->run();

		return new CommandResult(1);
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

	private function affectedRows(mixed $result): int
	{
		return is_int($result) && $result >= 0 ? $result : 0;
	}
}
