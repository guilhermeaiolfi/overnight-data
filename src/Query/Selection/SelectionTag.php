<?php

declare(strict_types=1);

namespace ON\Data\Query\Selection;

final class SelectionTag
{
	public const COLUMN = 'column';

	/** User-authored selection (replaces SelectionItem::$explicit). */
	public const EXPLICIT = 'explicit';

	/** Not visible in public output / place (opt-out; default is visible). */
	public const INTERNAL = 'internal';

	public const IDENTITY = 'identity';

	public const RELATION = 'relation';

	public const REQUIRED = 'required';

	public const SQL_ONLY = 'sql-only';

	public const DEFAULT = 'default';
}
