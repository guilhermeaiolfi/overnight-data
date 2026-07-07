<?php

declare(strict_types=1);

namespace ON\Data\Database;

final class ConnectionConfig
{
	/**
	 * @param array<string, mixed> $options
	 * @param list<string> $schema
	 */
	public function __construct(
		public readonly string $driver = 'sqlite',
		public readonly string $databaseName = 'default',
		public readonly string $connectionName = 'default',
		public readonly string $tablePrefix = '',
		public readonly ?string $dsn = null,
		public readonly ?string $username = null,
		public readonly ?string $password = null,
		public readonly array $options = [],
		public readonly array $schema = ['public'],
	) {
	}

	public static function dsn(
		string $driver,
		string $dsn,
		?string $username = null,
		?string $password = null,
		string $databaseName = 'default',
		string $connectionName = 'default',
		string $tablePrefix = '',
		array $options = [],
		array $schema = ['public'],
	): self {
		return new self(
			driver: $driver,
			databaseName: $databaseName,
			connectionName: $connectionName,
			tablePrefix: $tablePrefix,
			dsn: $dsn,
			username: $username,
			password: $password,
			options: $options,
			schema: $schema,
		);
	}
}
