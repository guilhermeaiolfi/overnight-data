<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation\Loader;

use ON\Data\Query\Exception\RelationLoaderException;
use ON\Data\Query\QuerySourceInterface;
use ON\Data\Query\Relation\LoadRuntime;
use ON\Data\Query\Relation\RelationRef;
use ON\Data\Query\Result\Parser\AbstractNode;

final class FirstOfManyLoader extends AbstractLoader
{
	protected function initNode(RelationRef $relation, LoadRuntime $runtime): AbstractNode
	{
		throw RelationLoaderException::loadingNotImplemented($relation);
	}

	public function join(RelationRef $relation): QuerySourceInterface
	{
		throw RelationLoaderException::firstOfManyJoinNotImplemented($relation);
	}
}
