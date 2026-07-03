<?php

declare(strict_types=1);

namespace ON\Data\ORM\Persistence;

interface CommandExecutorInterface
{
	public function execute(CommandInterface $command): CommandResult;
}
