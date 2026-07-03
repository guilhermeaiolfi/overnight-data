<?php

declare(strict_types=1);

namespace ON\Data\ORM\Persistence;

use ON\Data\ORM\Exception\InvalidCommandException;

final class CommandResult
{
	/**
	 * @param array<string, mixed> $generatedValues
	 */
	public function __construct(
		private int $affectedRows,
		private array $generatedValues = [],
	) {
		if ($affectedRows < 0) {
			throw new InvalidCommandException('Command result affected rows cannot be negative.');
		}
	}

	public function getAffectedRows(): int
	{
		return $this->affectedRows;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getGeneratedValues(): array
	{
		return $this->generatedValues;
	}
}
