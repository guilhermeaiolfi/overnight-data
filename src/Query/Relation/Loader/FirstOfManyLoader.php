<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation\Loader;

use ON\Data\Query\Exception\RelationLoaderException;
use ON\Data\Query\QuerySourceInterface;
use ON\Data\Query\Relation\LoadRuntime;
use ON\Data\Query\Relation\RelationLoadBranch;
use ON\Data\Query\Relation\RelationRef;
use ON\Data\Query\Result\Parser\AbstractNode;

final class FirstOfManyLoader extends AbstractLoader
{
	protected function initNode(RelationLoadBranch $branch, LoadRuntime $runtime): AbstractNode
	{
		throw RelationLoaderException::loadingNotImplemented($branch->getRelationRef());
	}

	public function join(RelationRef $relation): QuerySourceInterface
	{
		throw RelationLoaderException::firstOfManyJoinNotImplemented($relation);
	}
}
