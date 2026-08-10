<?php

declare(strict_types=1);

namespace ON\Data\Query\Result;

use ON\Data\ORM\Representation\Schema\RepresentationSchema;

/**
 * Opaque token from {@see WritableResultHandler::prepare()} for one mutable fetch.
 *
 * Query code must not inspect the concrete type; only the same handler's
 * {@see WritableResultHandler::track()} understands it. The usual concrete value is
 * the ORM query representation plan.
 *
 * When prepare already built a place {@see RepresentationSchema} (after identity
 * planning), {@see getFetchSchema()} returns it so {@see SelectQuery} does not
 * recompile.
 */
interface WritablePreparation
{
	public function getFetchSchema(): ?RepresentationSchema;
}
