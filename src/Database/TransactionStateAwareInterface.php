<?php

declare(strict_types=1);

namespace ON\Data\Database;

/** Optional capability for transaction boundaries that need to distinguish commits from savepoints. */
interface TransactionStateAwareInterface
{
	/** Returns null when the underlying adapter cannot report its transaction state. */
	public function isInTransaction(): ?bool;
}
