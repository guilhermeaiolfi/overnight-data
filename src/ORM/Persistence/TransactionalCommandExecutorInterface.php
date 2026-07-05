<?php

declare(strict_types=1);

namespace ON\Data\ORM\Persistence;

interface TransactionalCommandExecutorInterface
{
	/**
	 * Runs the callback inside one database transaction.
	 * Commits when the callback returns normally.
	 * Rolls back and rethrows when the callback throws.
	 *
	 * @template T
	 * @param callable(): T $callback
	 * @return T
	 */
	public function transaction(callable $callback): mixed;
}
