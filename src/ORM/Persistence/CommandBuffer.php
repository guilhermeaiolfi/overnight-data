<?php

declare(strict_types=1);

namespace ON\Data\ORM\Persistence;

final class CommandBuffer
{
	/** @var list<CommandInterface> */
	private array $commands = [];

	public function add(CommandInterface $command): void
	{
		$this->commands[] = $command;
	}

	/**
	 * @return list<CommandInterface>
	 */
	public function getAll(): array
	{
		return $this->commands;
	}

	public function clear(): void
	{
		$this->commands = [];
	}

	public function isEmpty(): bool
	{
		return $this->commands === [];
	}
}
