<?php

declare(strict_types=1);

namespace Tests\ON\Data\Support;

use ON\Data\ORM\Persistence\WriteCommandInterface;
use ON\Data\ORM\Persistence\WriteExecutorInterface;
use ON\Data\ORM\Persistence\WriteResult;

final class RecordingWriteExecutor implements WriteExecutorInterface
{
	/** @var list<WriteCommandInterface> */
	private array $commands = [];

	public function __construct(
		private WriteResult $result = new WriteResult(1),
	) {
	}

	public function execute(WriteCommandInterface $command): WriteResult
	{
		$this->commands[] = $command;

		return $this->result;
	}

	/**
	 * @return list<WriteCommandInterface>
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
