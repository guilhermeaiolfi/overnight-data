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
		$pairing = $relationRef->getDefinition()->getKeyPairing();

		return new SingularNode(
			$branch->columns(),
			$relationRef->getCollection()->getPrimaryKey(),
			$pairing->getRightFields(),
			$pairing->getLeftFields(),
		);
	}

	public function load(RelationLoadBranch $branch, LoadRuntime $runtime): void
	{
		$relationRef = $branch->getRelationRef();
		$definition = $relationRef->getDefinition();
		$parentToChild = $definition->getKeyPairing();
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

		$branch->requireFields($relationRef->getCollection()->getPrimaryKey());
		$branch->requireFields($parentToChild->getRightFields());
		$parentBranch->requireFields($parentToChild->getLeftFields());

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
