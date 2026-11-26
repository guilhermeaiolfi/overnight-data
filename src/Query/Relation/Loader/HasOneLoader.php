<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation\Loader;

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
		if ($runtime->getLoadStrategy($branch) === LoadStrategy::SEPARATE_QUERY) {
			$this->configureSeparateQuery($branch, $runtime, $this->loadData(...));

			return;
		}

		$this->assertNoJoinedSelectionOptions($branch);
		$queryRelation = $runtime->getQueryRelation($branch);
		$source = $this->join($queryRelation);
		$runtime->setQueryContext($branch, $queryRelation->getQuery(), $source, $queryRelation);
		$this->collectByKeyPairing($branch, $runtime);
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
