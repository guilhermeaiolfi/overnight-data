<?php

declare(strict_types=1);

namespace ON\Data\Query\Exception;

use LogicException;
use ON\Data\Query\SelectQuery;

/**
 * Thrown when {@see SelectQuery::count()} cannot determine root identity.
 *
 * The query graph itself is valid; counting matching root rows is undefined
 * without a usable collection primary key.
 */
final class CountRequiresRootIdentityException extends LogicException
{
	public static function forQuery(SelectQuery $query): self
	{
		return new self(
			'SelectQuery::count() requires a usable root identity. '
			. 'Count a collection-root query or project a root identity first.'
		);
	}
}
