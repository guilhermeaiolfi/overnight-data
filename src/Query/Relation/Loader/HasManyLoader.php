<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation\Loader;

use ON\Data\Query\Relation\LoadRuntime;
use ON\Data\Query\Relation\RelationRef;
use ON\Data\Query\Result\Parser\CollectionNode;

final class HasManyLoader extends AbstractLoader
{
	public function load(RelationRef $relation, LoadRuntime $runtime): void
	{
		$runtime->register(new CollectionNode(
			$runtime->getNodeColumns(),
			$runtime->getNodeIdentityFields(),
			$runtime->getNodeChildFields(),
			$runtime->getNodeParentFields(),
		));
	}
}
