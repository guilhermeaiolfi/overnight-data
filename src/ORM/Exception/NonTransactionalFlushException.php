<?php

declare(strict_types=1);

namespace ON\Data\ORM\Exception;

/**
 * Raised when {@see \ON\Data\ORM\Persistence\FlushExecutor} is asked to flush
 * with a command executor that does not implement
 * {@see \ON\Data\ORM\Persistence\TransactionalCommandExecutorInterface}.
 */
final class NonTransactionalFlushException extends PersistenceException
{
}
