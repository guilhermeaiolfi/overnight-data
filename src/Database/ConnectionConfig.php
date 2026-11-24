<?php

declare(strict_types=1);

namespace ON\Data\Database;

use InvalidArgumentException;

final readonly class ConnectionConfig
{
	/** @param array<int, mixed> $options PDO constructor options */
	public function __construct(
		public string $dsn,
		public ?string $username = null,
		public ?string $password = null,
		public array $options = [],
		public string $tablePrefix = '',
		public ?bool $insertReturning = null,
	) {
		if (trim($dsn) === '') {
			throw new InvalidArgumentException('ConnectionConfig requires a PDO DSN.');
		}
	}
}
