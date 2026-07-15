<?php

declare(strict_types=1);

namespace ON\Data\ORM\Exception;

use ON\Data\ORM\Persistence\FlushExecutor;
use ON\Data\ORM\Persistence\TransactionalCommandExecutorInterface;

/**
 * Raised when {@see FlushExecutor} is asked to flush
 * with a command executor that does not implement
 * {@see TransactionalCommandExecutorInterface}.
 */
final class NonTransactionalFlushException extends PersistenceException
{
}
