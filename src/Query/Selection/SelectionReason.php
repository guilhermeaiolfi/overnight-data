<?php

declare(strict_types=1);

namespace ON\Data\Query\Selection;

final class SelectionReason
{
	public const PUBLIC = 'public';

	public const IDENTITY = 'identity';

	public const RELATION = 'relation';

	public const REQUIRED = 'required';

	public const INTERNAL = 'internal';

	public const EXPLICIT = 'explicit';
}
