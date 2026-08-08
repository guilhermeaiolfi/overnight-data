<?php

declare(strict_types=1);

namespace ON\Data\Query\Result;

use ON\Data\Query\Projection\ProjectionLayout;

/**
 * Opaque token from {@see WritableResultHandler::prepare()} for one mutable fetch.
 *
 * Query code must not inspect the concrete type; only the same handler's
 * {@see WritableResultHandler::track()} understands it. The usual concrete value is
 * the ORM query representation plan.
 *
 * When prepare already built a {@see ProjectionLayout} (after identity planning),
 * {@see getProjectionLayout()} returns it so {@see SelectQuery} does not rebuild it.
 */
interface WritablePreparation
{
	public function getProjectionLayout(): ?ProjectionLayout;
}
