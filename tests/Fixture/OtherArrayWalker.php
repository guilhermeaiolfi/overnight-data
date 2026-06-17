<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use Closure;
use ON\Data\Mapper\MappingContext;
use ON\Data\Mapper\Walker\WalkerInterface;

final class OtherArrayWalker implements WalkerInterface
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
