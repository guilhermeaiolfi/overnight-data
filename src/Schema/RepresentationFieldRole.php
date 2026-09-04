<?php

declare(strict_types=1);

namespace ON\Data\Schema;

/**
 * Path role for a field on {@see RepresentationSchema}.
 *
 * Converges the durable half of query selection meaning (public vs not
 * user-selected path), not fetch tags (COLUMN / SQL_ONLY / DEFAULT).
 */
enum RepresentationFieldRole
{
	/** User-facing / explicitly selected path on the representation. */
	case Public;

	/** Not part of the authored path shape; present for adoption/tracking (e.g. PK backfill). */
	case Implicit;
}
