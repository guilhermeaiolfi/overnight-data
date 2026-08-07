<?php

declare(strict_types=1);

namespace ON\Data\Query\Result;

use ON\Data\Query\Load\FetchPlan;

/**
 * Opaque token from {@see WritableResultHandler::prepare()} for one mutable fetch.
 *
 * Query code must not inspect the concrete type; only the same handler's
 * {@see WritableResultHandler::track()} understands it. The usual concrete value is
 * the ORM query representation plan.
 *
 * When prepare already compiled a {@see FetchPlan} (after identity planning),
 * {@see getFetchPlan()} returns it so {@see SelectQuery} does not recompile.
 */
interface WritablePreparation
{
	public function getFetchPlan(): ?FetchPlan;
}
