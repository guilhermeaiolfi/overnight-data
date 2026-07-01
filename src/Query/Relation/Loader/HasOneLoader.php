<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation\Loader;

use ON\Data\Query\Relation\LoadRuntime;
use ON\Data\Query\Relation\LoadStrategy;
use ON\Data\Query\Relation\RelationLoadBranch;
use ON\Data\Query\Result\Parser\AbstractNode;
use ON\Data\Query\Result\Parser\SingularNode;

final class HasOneLoader extends AbstractLoader
{
	public function getDefaultLoadStrategy(): LoadStrategy
	{
		return LoadStrategy::JOIN;
	}

	protected function initNode(RelationLoadBranch $branch, LoadRuntime $runtime): AbstractNode
	{
		$relationRef = $branch->getRelationRef();
		$definition = $relationRef->getDefinition();
		$keyPairing = $definition->getKeyPairing();
		$parentBranch = $branch->getParent();
		$identity = $branch->requireFields($relationRef->getCollection()->getPrimaryKey());
		$child = $keyPairing->requireRight($branch);
		$parent = $keyPairing->requireLeft($parentBranch);

		return new SingularNode(
			$branch->getParserFields(),
			$identity,
			$child,
			$parent,
		);
	}

	public function load(RelationLoadBranch $branch, LoadRuntime $runtime): void
	{
		$relationRef = $branch->getRelationRef();
		$definition = $relationRef->getDefinition();
		$keyPairing = $definition->getKeyPairing();
		$parentBranch = $branch->getParent();
		$branch->requireFields($relationRef->getCollection()->getPrimaryKey());
		$keyPairing->requireRight($branch);
		$keyPairing->requireLeft($parentBranch);

		$branch->setJoinedAttachment(
			$runtime->getLoadStrategy($this->getDefaultLoadStrategy()) === LoadStrategy::JOIN,
		);

		$queryRelation = $runtime->getQueryRelation($branch);
		$source = $this->join($queryRelation);

		$runtime->setQueryContext($branch, $queryRelation->getQuery(), $source, $queryRelation);
	}
}
