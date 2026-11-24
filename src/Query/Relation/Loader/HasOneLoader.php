<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation\Loader;

use ON\Data\Query\Relation\KeyPairCollector;
use ON\Data\Query\Relation\LoadRuntime;
use ON\Data\Query\Relation\LoadStrategy;
use ON\Data\Query\Relation\RelationLoadBranch;

class HasOneLoader extends AbstractLoader
{
	public function getDefaultLoadStrategy(): LoadStrategy
	{
		return LoadStrategy::JOIN;
	}

	public function load(RelationLoadBranch $branch, LoadRuntime $runtime): void
	{
		$relationRef = $branch->getRelationRef();
		$definition = $relationRef->getDefinition();
		$parentToChild = $definition->getKeyPairing();
		$parentBranch = $branch->getParent();
		$strategy = $runtime->getLoadStrategy($branch);
		$runtime->collectWith($branch, new KeyPairCollector(
			$parentToChild->getLeftFields(),
			$parentToChild->getRightFields(),
		));

		if ($strategy === LoadStrategy::SEPARATE_QUERY) {
			$query = $runtime->createQuery($relationRef->getCollection());
			$runtime->setQueryContext($branch, $query, $query);
			$runtime->continueWith($branch, $this->loadData(...));
		} else {
			$this->assertNoJoinedSelectionOptions($branch);
			$queryRelation = $runtime->getQueryRelation($branch);
			$source = $this->join($queryRelation);
			$runtime->setQueryContext($branch, $queryRelation->getQuery(), $source, $queryRelation);
		}

		$branch->requireFields($relationRef->getCollection()->getPrimaryKey());
		$branch->requireFields($parentToChild->getRightFields());
		$parentBranch->requireFields($parentToChild->getLeftFields());
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
