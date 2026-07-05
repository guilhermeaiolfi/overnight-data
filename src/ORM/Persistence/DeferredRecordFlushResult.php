<?php

declare(strict_types=1);

namespace ON\Data\ORM\Persistence;

final class DeferredRecordFlushResult
{
	/**
	 * @param list<CommandResult> $commandResults
	 * @param list<callable(): void> $finalizers
	 */
	public function __construct(
		private readonly array $commandResults,
		private readonly array $finalizers,
	) {
	}

	/**
	 * @return list<CommandResult>
	 */
	public function getCommandResults(): array
	{
		return $this->commandResults;
	}

	public function finalize(): void
	{
		foreach ($this->finalizers as $finalizer) {
			$finalizer();
		}
	}
}
