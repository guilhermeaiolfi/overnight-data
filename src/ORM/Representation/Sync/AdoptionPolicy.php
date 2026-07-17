<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Sync;

/**
 * How adoption materializes RecordState for a representation (or flat source).
 *
 * Hydrate — existing row as a clean snapshot (writable query export).
 * Patch — existing row with present fields applied as dirty updates (Session::update).
 * Create — new RecordState (Session::create / flat create).
 */
enum AdoptionPolicy
{
	case Hydrate;
	case Patch;
	case Create;
}
