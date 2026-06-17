<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Walker;

use Closure;
use ON\Data\Mapper\MappingContext;

final class ArrayWalker implements WalkerInterface
{
	public static function canWalk(
		mixed $source,
		MappingContext $context,
	): bool {
		return is_array($source);
	}

	public function walk(
		mixed $source,
		MappingContext $context,
		Closure $visit,
	): void {
		foreach ($source as $name => $value) {
			$visit($name, $value, null);
		}
	}
}
