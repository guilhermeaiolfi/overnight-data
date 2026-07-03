<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation\Loader;

use ON\Data\Query\Relation\LoadRuntime;
use ON\Data\Query\Relation\LoadStrategy;
use ON\Data\Query\Relation\RelationKeyQuery;
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
		$identity = $branch->requireFields($relationRef->getCollection()->getPrimaryKey());
		$child = $branch->requireFields($ownerToTarget->getRightFields());
		$parent = $parentBranch->requireFields($ownerToTarget->getLeftFields());

		return new SingularNode(
			$this->columnSelectionKeys($branch),
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
		$branch->requireFields($relationRef->getCollection()->getPrimaryKey());
		$branch->requireFields($ownerToTarget->getRightFields());
		$parentBranch->requireFields($ownerToTarget->getLeftFields());

		$strategy = $runtime->getLoadStrategy($branch);
		$branch->setJoinedAttachment($strategy === LoadStrategy::JOIN);

		if ($strategy === LoadStrategy::SEPARATE_QUERY) {
			$query = $runtime->createQuery($relationRef->getCollection());

			$runtime->setQueryContext($branch, $query, $query);
			$runtime->continueWith($branch, 'loadData');

			return;
		}

		$this->assertNoJoinedSelectionOptions($branch);

		$queryRelation = $runtime->getQueryRelation($branch);
		$source = $this->join($queryRelation);

		$runtime->setQueryContext($branch, $queryRelation->getQuery(), $source, $queryRelation);
	}

	public function loadData(RelationLoadBranch $branch, LoadRuntime $runtime): void
	{
		$references = $branch->getReferenceValues();

		if ($references === []) {
			return;
		}

		$query = $branch->getQuery();
		RelationKeyQuery::filterRightByLeftReferences(
			$branch->getRelationRef()->getDefinition()->getKeyPairing(),
			$query,
			$query,
			$references,
		);
		$this->applySeparateQueryOptions($branch);
		$runtime->execute($branch, $query);
	}

}
