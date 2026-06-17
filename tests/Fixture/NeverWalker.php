<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use ON\Data\Mapper\MappingContext;
use ON\Data\Mapper\Walker\Walker;

final class NeverWalker extends Walker
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

		return false;
	}

	protected function getNodes(
		mixed $source,
		MappingContext $context,
	): iterable {
		ComponentTestState::recordRuntime(self::class, $context->getPath());

		return [];
	}
}
