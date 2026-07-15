<?php

declare(strict_types=1);

namespace ON\Data\Query\Result;

/**
 * Opaque token from {@see MutableResultHandler::prepare()} for one mutable fetch.
 *
 * Query code must not inspect the concrete type; only the same handler's
 * {@see MutableResultHandler::track()} understands it. The usual concrete value is
 * the ORM query representation plan.
 */
interface MutablePreparation
{
}
