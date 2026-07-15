<?php

declare(strict_types=1);

namespace ON\Data\Query\Condition;

use ON\Data\Query\SelectQuery;

final class ConditionTag
{
	/** Caller {@see SelectQuery::where()} / bindConditions(). */
	public const USER = 'user';

	/** Separate-query parent-key IN / OR correlation (chunk-replaced). */
	public const CORRELATION = 'correlation';

	/** Reserved for definition scopes / soft-delete / tenant filters. */
	public const SCOPE = 'scope';

	/** Reserved for pipeline-owned predicates that must not surface as user intent. */
	public const INTERNAL = 'internal';
}
