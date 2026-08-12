<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Schema;

/**
 * Place role for a field path on {@see RepresentationSchema}.
 *
 * Converges the durable half of query selection meaning (public vs not
 * user-selected place), not fetch tags (COLUMN / SQL_ONLY / DEFAULT).
 */
enum RepresentationFieldRole
{
	/** User-facing / explicitly selected place path on the representation. */
	case Public;

	/** Not part of the authored place shape; present for adoption/tracking (e.g. PK backfill). */
	case Implicit;
}
