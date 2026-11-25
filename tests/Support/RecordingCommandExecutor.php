<?php

declare(strict_types=1);

namespace Tests\ON\Data\Support;

use ON\Data\ORM\Persistence\CommandInterface;
use ON\Data\ORM\Persistence\CommandResult;
use ON\Data\ORM\Persistence\TransactionalCommandExecutorInterface;

final class RecordingCommandExecutor implements TransactionalCommandExecutorInterface
{
	/** @var list<CommandInterface> */
	private array $commands = [];

	/**
	 * @param list<CommandResult> $results
	 */
	public function __construct(
		private CommandResult $result = new CommandResult(1),
		private array $results = [],
	) {
	}

	public function execute(CommandInterface $command): CommandResult
	{
		$this->commands[] = $command;

		return array_shift($this->results) ?? $this->result;
	}

	public function isInTransaction(): ?bool
	{
		return null;
	}

	public function transaction(callable $callback): mixed
	{
		return $callback();
	}

	/**
	 * @return list<CommandInterface>
	 */
	public function getCommands(): array
	{
		return $this->commands;
	}

	public function clear(): void
	{
		$this->commands = [];
	}
}
