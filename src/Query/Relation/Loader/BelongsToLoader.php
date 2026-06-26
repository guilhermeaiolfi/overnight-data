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

	public function collectFields(RelationRef $relation, LoadRuntime $runtime): void
	{
		$runtime->requireBranchFields($relation->getCollection()->getPrimaryKey());
		$runtime->requireBranchFields($this->relationKeys($relation, 'outer'));
		$runtime->requireParentFields($this->relationKeys($relation, 'inner'));
	}

	public function register(RelationRef $relation, LoadRuntime $runtime): AbstractNode
	{
		$identity = $runtime->requireBranchFields($relation->getCollection()->getPrimaryKey());
		$child = $runtime->requireBranchFields($this->relationKeys($relation, 'outer'));
		$parent = $runtime->requireParentFields($this->relationKeys($relation, 'inner'));
		$node = new SingularNode(
			$runtime->getNodeColumns(),
			$identity,
			$child,
			$parent,
		);
		$strategy = $runtime->getLoadStrategy($this->getDefaultLoadStrategy());

		if ($strategy === LoadStrategy::JOIN) {
			$runtime->getParentNode()->joinNode($relation->getName(), $node);

			return $node;
		}

		$runtime->getParentNode()->linkNode($relation->getName(), $node);

		return $node;
	}

	public function load(RelationRef $relation, LoadRuntime $runtime): void
	{
		$queryRelation = $runtime->getQueryRelation();
		$source = $this->join($queryRelation);

		$runtime->setQueryContext($queryRelation->getQuery(), $source, $queryRelation);
	}
}
