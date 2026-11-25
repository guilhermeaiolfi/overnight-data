<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Sync;

/**
 * How adoption materializes RecordState for a representation (or flat source).
 *
 * Patch — existing row with present fields applied as dirty updates (Session::update).
 * Create — new RecordState (Session::create / flat create).
 */
enum AdoptionPolicy
{
	case Patch;
	case Create;
}
