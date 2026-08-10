<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Schema;

/**
 * Place role for a field path on {@see RepresentationSchema}.
 *
 * Converges the durable half of query selection meaning (public vs identity),
 * not fetch tags (COLUMN / SQL_ONLY / DEFAULT).
 */
enum RepresentationFieldRole
{
	/** User-facing place path on the representation. */
	case Public;

	/** System identity enrichment for adoption; not public place. */
	case Identity;
}
