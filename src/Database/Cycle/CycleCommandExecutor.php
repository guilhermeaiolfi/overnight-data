<?php

declare(strict_types=1);

namespace ON\Data\Database\Cycle;

use Cycle\Database\DatabaseInterface;
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
			->insert($command->getCollectionName())
			->values($command->getValues())
			->run();

		return new CommandResult(1);
	}

	private function update(UpdateCommand $command): CommandResult
	{
		$query = $this->database
			->update($command->getCollectionName())
			->values($command->getChanges());

		foreach ($command->getIdentity() as $field => $value) {
			$query->where($field, $value);
		}

		return new CommandResult($this->affectedRows($query->run()));
	}

	private function delete(DeleteCommand $command): CommandResult
	{
		$query = $this->database->delete($command->getCollectionName());

		foreach ($command->getIdentity() as $field => $value) {
			$query->where($field, $value);
		}

		return new CommandResult($this->affectedRows($query->run()));
	}

	private function affectedRows(mixed $result): int
	{
		return is_int($result) && $result >= 0 ? $result : 0;
	}
}
