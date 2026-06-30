<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation\Loader;

use ON\Data\Query\Relation\LoadRuntime;
use ON\Data\Query\Relation\LoadStrategy;
use ON\Data\Query\Relation\RelationLoadBranch;
use ON\Data\Query\Result\Parser\AbstractNode;
use ON\Data\Query\Result\Parser\SingularNode;

final class BelongsToLoader extends AbstractLoader
{
	public function getDefaultLoadStrategy(): LoadStrategy
	{
		return LoadStrategy::JOIN;
	}

	protected function initNode(RelationLoadBranch $branch, LoadRuntime $runtime): AbstractNode
	{
		$relationRef = $branch->getRelationRef();
		$definition = $relationRef->getDefinition();
		$parentBranch = $branch->getParent();
		$identity = $branch->requireFields($relationRef->getCollection()->getPrimaryKey());
		$child = $branch->requireFields($definition->getOuterKeys());
		$parent = $parentBranch->requireFields($definition->getInnerKeys());

		return new SingularNode(
			$runtime->getParserFields(),
			$identity,
			$child,
			$parent,
		);
	}

	public function load(RelationLoadBranch $branch, LoadRuntime $runtime): void
	{
		$relationRef = $branch->getRelationRef();
		$definition = $relationRef->getDefinition();
		$parentBranch = $branch->getParent();
		$branch->requireFields($relationRef->getCollection()->getPrimaryKey());
		$branch->requireFields($definition->getOuterKeys());
		$parentBranch->requireFields($definition->getInnerKeys());

		$runtime->setJoinedAttachment(
			$runtime->getLoadStrategy($this->getDefaultLoadStrategy()) === LoadStrategy::JOIN,
		);

		$queryRelation = $runtime->getQueryRelation();
		$source = $this->join($queryRelation);

		$runtime->setQueryContext($queryRelation->getQuery(), $source, $queryRelation);
	}
}
