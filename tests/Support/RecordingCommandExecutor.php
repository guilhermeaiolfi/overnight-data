<?php

declare(strict_types=1);

namespace Tests\ON\Data\Support;

use ON\Data\ORM\Persistence\CommandExecutorInterface;
use ON\Data\ORM\Persistence\CommandInterface;
use ON\Data\ORM\Persistence\CommandResult;

final class RecordingCommandExecutor implements CommandExecutorInterface
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
