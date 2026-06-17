<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use Closure;
use ON\Data\Mapper\MappingContext;
use ON\Data\Mapper\Walker\WalkerInterface;

final class SpyArrayWalker implements WalkerInterface
{
	public function __construct()
	{
		ComponentTestState::recordConstruction(self::class);
	}

	public static function canWalk(
		mixed $source,
		MappingContext $context,
	): bool {
		ComponentTestState::recordSelection(self::class);

		return is_array($source);
	}

	public function walk(
		mixed $source,
		MappingContext $context,
		Closure $visit,
	): void {
		ComponentTestState::recordRuntime(self::class, $context->getPath());

		foreach ($source as $name => $value) {
			$visit($name, $value, null);
		}
	}
}
