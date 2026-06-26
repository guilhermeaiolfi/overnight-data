<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation\Loader;

use ON\Data\Query\Relation\LoadRuntime;
use ON\Data\Query\Relation\LoadStrategy;
use ON\Data\Query\Relation\RelationRef;
use ON\Data\Query\Result\Parser\SingularNode;

final class BelongsToLoader extends AbstractLoader
{
	public function getDefaultLoadStrategy(): LoadStrategy
	{
		return LoadStrategy::JOIN;
	}

	public function load(RelationRef $relation, LoadRuntime $runtime): void
	{
		$runtime->register(new SingularNode(
			$runtime->getNodeColumns(),
			$runtime->getNodeIdentityFields(),
			$runtime->getNodeChildFields(),
			$runtime->getNodeParentFields(),
		));
	}
}
