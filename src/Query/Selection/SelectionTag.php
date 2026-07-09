<?php

declare(strict_types=1);

namespace ON\Data\Query\Selection;

final class SelectionTag
{
	public const COLUMN = 'column';

	public const PUBLIC = 'public';

	public const IDENTITY = 'identity';

	public const RELATION = 'relation';

	public const REQUIRED = 'required';

	public const INTERNAL = 'internal';

	public const SQL_ONLY = 'sql-only';

	public const DEFAULT = 'default';
}
