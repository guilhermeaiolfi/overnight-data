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

	public function register(RelationRef $relation, LoadRuntime $runtime): AbstractNode
	{
		$identity = $runtime->requireBranchFields($relation->getCollection()->getPrimaryKey());
		$child = $runtime->requireBranchFields($this->relationKeys($relation, 'outer'));
		$parent = $runtime->requireParentFields($this->relationKeys($relation, 'inner'));
		
		return new SingularNode(
			$runtime->getNodeColumns(),
			$identity,
			$child,
			$parent,
		);
	}

	public function load(RelationRef $relation, LoadRuntime $runtime): void
	{
		$runtime->setJoinedAttachment(
			$runtime->getLoadStrategy($this->getDefaultLoadStrategy()) === LoadStrategy::JOIN,
		);

		$queryRelation = $runtime->getQueryRelation();
		$source = $this->join($queryRelation);

		$runtime->setQueryContext($queryRelation->getQuery(), $source, $queryRelation);
	}
}
