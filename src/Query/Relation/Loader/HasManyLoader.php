<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation\Loader;

use ON\Data\Query\Relation\LoadRuntime;
use ON\Data\Query\Relation\LoadStrategy;
use ON\Data\Query\Relation\RelationLoadBranch;
use ON\Data\Query\Result\Parser\AbstractNode;
use ON\Data\Query\Result\Parser\CollectionNode;

final class HasManyLoader extends AbstractLoader
{
	protected function initNode(RelationLoadBranch $branch, LoadRuntime $runtime): AbstractNode
	{
		$relationRef = $branch->getRelationRef();
		$definition = $relationRef->getDefinition();
		$keyPairing = $definition->getKeyPairing();
		$parentBranch = $branch->getParent();
		$identity = $branch->requireFields($relationRef->getCollection()->getPrimaryKey());
		$child = $keyPairing->requireRight($branch);
		$parent = $keyPairing->requireLeft($parentBranch);

		return new CollectionNode(
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

		$strategy = $runtime->getLoadStrategy($this->getDefaultLoadStrategy());
		$branch->setJoinedAttachment($strategy === LoadStrategy::JOIN);

		if ($strategy === LoadStrategy::JOIN) {
			$queryRelation = $runtime->getQueryRelation($branch);
			$source = $this->join($queryRelation);

			$runtime->setQueryContext($branch, $queryRelation->getQuery(), $source, $queryRelation);

			return;
		}

		$query = $runtime->createQuery($relationRef->getCollection());

		$runtime->setQueryContext($branch, $query, $query);
		$runtime->continueWith($branch, 'loadData');
	}

	public function loadData(RelationLoadBranch $branch, LoadRuntime $runtime): void
	{
		$references = $branch->getReferenceValues();

		if ($references === []) {
			return;
		}

		$query = $branch->getQuery();
		$branch->getRelationRef()->getDefinition()->getKeyPairing()->filterRightByLeftReferences(
			$query,
			$query,
			$references,
		);
		$runtime->execute($branch, $query);
	}
}
