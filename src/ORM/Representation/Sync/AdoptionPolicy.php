<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Sync;

/**
 * How adoption materializes RecordState for a representation (or flat source).
 *
 * Hydrate — existing row as a clean snapshot (writable query export).
 * Identify — existing row as a key-only clean snapshot ({@see Session::identify()}; no field patch).
 * Patch — existing row with present fields applied as dirty updates (Session::update).
 * Create — new RecordState (Session::create / flat create).
 */
enum AdoptionPolicy
{
	case Hydrate;
	case Identify;
	case Patch;
	case Create;
}
