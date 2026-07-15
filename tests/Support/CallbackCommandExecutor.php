<?php

declare(strict_types=1);

namespace Tests\ON\Data\Support;

use Closure;
use ON\Data\ORM\Persistence\CommandExecutorInterface;
use ON\Data\ORM\Persistence\CommandInterface;
use ON\Data\ORM\Persistence\CommandResult;
use ON\Data\ORM\Persistence\TransactionalCommandExecutorInterface;

/**
 * Transactional test executor that records commands and delegates results to a callback.
 *
 * @phpstan-type ExecuteCallback Closure(CommandInterface, list<CommandInterface>): CommandResult
 */
final class CallbackCommandExecutor implements CommandExecutorInterface, TransactionalCommandExecutorInterface
{
	/** @var list<CommandInterface> */
	private array $commands = [];

	public int $transactions = 0;

	/**
	 * @param ExecuteCallback $callback
	 */
	public function __construct(
		private readonly Closure $callback,
	) {
	}

	public function execute(CommandInterface $command): CommandResult
	{
		$this->commands[] = $command;

		return ($this->callback)($command, $this->commands);
	}

	public function transaction(callable $callback): mixed
	{
		++$this->transactions;

		return $callback();
	}

	/**
	 * @return list<CommandInterface>
	 */
	public function getCommands(): array
	{
		return $this->commands;
	}
}
