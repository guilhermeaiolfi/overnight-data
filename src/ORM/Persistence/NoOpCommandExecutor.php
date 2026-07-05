<?php

declare(strict_types=1);

namespace ON\Data\ORM\Persistence;

final class NoOpCommandExecutor implements CommandExecutorInterface
{
	public function execute(CommandInterface $command): CommandResult
	{
		return new CommandResult(0);
	}
}
