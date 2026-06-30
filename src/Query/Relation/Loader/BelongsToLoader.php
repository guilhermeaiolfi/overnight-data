<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation\Loader;

use ON\Data\Query\Relation\LoadRuntime;
use ON\Data\Query\Relation\LoadStrategy;
use ON\Data\Query\Relation\RelationRef;
use ON\Data\Query\Result\Parser\AbstractNode;
use ON\Data\Query\Result\Parser\SingularNode;

final class BelongsToLoader extends AbstractLoader
{
	public function getDefaultLoadStrategy(): LoadStrategy
	{
		return LoadStrategy::JOIN;
	}

	protected function initNode(RelationRef $relation, LoadRuntime $runtime): AbstractNode
	{
		$definition = $relation->getRelation();
		$current = $runtime->getCurrentBranch();
		$parentBranch = $runtime->requireParentBranch();
		$identity = $current->requireFields($relation->getCollection()->getPrimaryKey());
		$child = $current->requireFields($definition->getOuterKeys());
		$parent = $parentBranch->requireFields($definition->getInnerKeys());

		return new SingularNode(
			$runtime->getNodeColumns(),
			$identity,
			$child,
			$parent,
		);
	}

	public function load(RelationRef $relation, LoadRuntime $runtime): void
	{
		$definition = $relation->getRelation();
		$current = $runtime->getCurrentBranch();
		$parentBranch = $runtime->requireParentBranch();
		$current->requireFields($relation->getCollection()->getPrimaryKey());
		$current->requireFields($definition->getOuterKeys());
		$parentBranch->requireFields($definition->getInnerKeys());

		$runtime->setJoinedAttachment(
			$runtime->getLoadStrategy($this->getDefaultLoadStrategy()) === LoadStrategy::JOIN,
		);

		$queryRelation = $runtime->getQueryRelation();
		$source = $this->join($queryRelation);

		$runtime->setQueryContext($queryRelation->getQuery(), $source, $queryRelation);
	}
}
