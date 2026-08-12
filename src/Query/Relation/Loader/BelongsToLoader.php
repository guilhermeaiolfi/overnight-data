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
		$ownerToTarget = $definition->getKeyPairing();
		$parentBranch = $branch->getParent();
		$identity = $runtime->requireFields($branch, $relationRef->getCollection()->getPrimaryKey());
		$child = $runtime->requireFields($branch, $ownerToTarget->getRightFields());
		$parent = $runtime->requireFields($parentBranch, $ownerToTarget->getLeftFields());

		return new SingularNode(
			$branch->localColumnLoadKeys(),
			$identity,
			$child,
			$parent,
		);
	}

	public function load(RelationLoadBranch $branch, LoadRuntime $runtime): void
	{
		$relationRef = $branch->getRelationRef();
		$definition = $relationRef->getDefinition();
		$ownerToTarget = $definition->getKeyPairing();
		$parentBranch = $branch->getParent();
		$strategy = $runtime->getLoadStrategy($branch);
		$branch->setJoinedAttachment($strategy === LoadStrategy::JOIN);

		if ($strategy === LoadStrategy::SEPARATE_QUERY) {
			$query = $runtime->createQuery($relationRef->getCollection());
			$runtime->setQueryContext($branch, $query, $query);
		} else {
			$this->assertNoJoinedSelectionOptions($branch);
			$queryRelation = $runtime->getQueryRelation($branch);
			$source = $this->join($queryRelation);
			$runtime->setQueryContext($branch, $queryRelation->getQuery(), $source, $queryRelation);
		}

		$runtime->requireFields($branch, $relationRef->getCollection()->getPrimaryKey());
		$runtime->requireFields($branch, $ownerToTarget->getRightFields());
		$runtime->requireFields($parentBranch, $ownerToTarget->getLeftFields());

		if ($strategy === LoadStrategy::SEPARATE_QUERY) {
			$runtime->continueWith($branch, 'loadData');
		}
	}

	public function loadData(RelationLoadBranch $branch, LoadRuntime $runtime): void
	{
		$this->applySeparateQueryOptions($branch);
		$this->executeSeparateByReferences(
			$branch,
			$runtime,
			$branch->getRelationRef()->getDefinition()->getKeyPairing(),
		);
	}
}
