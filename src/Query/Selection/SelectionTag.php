<?php

declare(strict_types=1);

namespace ON\Data\Query\Selection;

final class SelectionTag
{
	/** User-authored selection. */
	public const EXPLICIT = 'explicit';

	/** Initial default star selection before any explicit select(). */
	public const DEFAULT = 'default';
}
