<?php

declare(strict_types=1);

namespace ON\Data\Database;

/**
 * Executes positional SQL parameters on a single connection.
 */
interface ConnectionInterface extends DatabasePlatformInterface
{
	/** An unprefixed connection returns an empty string. */
	public function tablePrefix(): string;

	/**
	 * @param list<mixed> $params
	 * @return list<array<string, mixed>>
	 */
	public function fetchAll(string $sql, array $params = []): array;

	/**
	 * @param list<mixed> $params
	 * @return array<string, mixed>|null
	 */
	public function fetchOne(string $sql, array $params = []): ?array;

	/**
	 * @param list<mixed> $params
	 * @return iterable<array<string, mixed>>
	 */
	public function iterate(string $sql, array $params = []): iterable;

	/** @param list<mixed> $params */
	public function execute(string $sql, array $params = []): int;

	public function lastInsertId(?string $sequence = null): string|false;

	/**
	 * @template T
	 * @param callable(): T $callback
	 * @return T
	 */
	public function transaction(callable $callback): mixed;
}
