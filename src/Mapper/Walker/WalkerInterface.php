<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Walker;

use Closure;
use ON\Data\Mapper\MappingContext;

interface WalkerInterface
{
	public static function canWalk(
		mixed $source,
		MappingContext $context,
	): bool;

	/**
	 * @param Closure(string|int, mixed, mixed): void $visit
	 */
	public function walk(
		mixed $source,
		MappingContext $context,
		Closure $visit,
	): void;
}
