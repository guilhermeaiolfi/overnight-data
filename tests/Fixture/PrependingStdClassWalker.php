<?php

declare(strict_types=1);

namespace Tests\ON\Data\Fixture;

use ON\Data\Mapper\MappingContext;
use ON\Data\Mapper\MappingNode;
use ON\Data\Mapper\Walker\Walker;
use stdClass;

final class PrependingStdClassWalker extends Walker
{
	public function __construct()
	{
		parent::__construct();
		ComponentTestState::recordConstruction(self::class);
	}

	public static function canWalk(
		mixed $source,
		MappingContext $context,
	): bool {
		ComponentTestState::recordSelection(self::class);

		return $source instanceof stdClass;
	}

	protected function getNodes(
		MappingNode $node,
	): iterable {
		ComponentTestState::recordRuntime(self::class, $node->getPath());

		yield $node->child('specialized', 'walker');
	}
}
