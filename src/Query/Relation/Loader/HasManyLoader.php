<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation\Loader;

use ON\Data\Query\Relation\LoadRuntime;
use ON\Data\Query\Relation\LoadStrategy;
use ON\Data\Query\Relation\RelationLoadBranch;
use ON\Data\Query\Result\Parser\AbstractNode;
use ON\Data\Query\Result\Parser\CollectionNode;
use function ON\Data\Query\x;

final class HasManyLoader extends AbstractLoader
{
	protected function initNode(RelationLoadBranch $branch, LoadRuntime $runtime): AbstractNode
	{
		$relationRef = $branch->getRelationRef();
		$definition = $relationRef->getDefinition();
		$parentBranch = $branch->getParent();
		$identity = $branch->requireFields($relationRef->getCollection()->getPrimaryKey());
		$child = $branch->requireFields($definition->getOuterKeys());
		$parent = $parentBranch->requireFields($definition->getInnerKeys());

		return new CollectionNode(
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

		$strategy = $runtime->getLoadStrategy($this->getDefaultLoadStrategy());
		$runtime->setJoinedAttachment($strategy === LoadStrategy::JOIN);

		if ($strategy === LoadStrategy::JOIN) {
			$queryRelation = $runtime->getQueryRelation();
			$source = $this->join($queryRelation);

			$runtime->setQueryContext($queryRelation->getQuery(), $source, $queryRelation);

			return;
		}

		$query = $runtime->createQuery($relationRef->getCollection());

		$runtime->setQueryContext($query, $query);
		$runtime->continueWith('loadData');
	}

	public function loadData(RelationLoadBranch $branch, LoadRuntime $runtime): void
	{
		$references = $runtime->getReferenceValues();

		if ($references === []) {
			return;
		}

		$query = $runtime->getQuery();
		$childFields = $branch->getRelationRef()->getDefinition()->getOuterKeys();

		if (count($childFields) === 1) {
			$query->where(
				x()->in(
					$query->field($childFields[0]),
					array_map(static fn (array $values) => array_values($values)[0], $references),
				),
			);

			$runtime->execute($query);

			return;
		}

		$predicates = [];

		foreach ($references as $values) {
			$comparisons = [];

			foreach ($childFields as $index => $fieldName) {
				$comparisons[] = x()->eq($query->field($fieldName), array_values($values)[$index]);
			}

			$predicates[] = x()->and(...$comparisons);
		}

		$query->where(x()->or(...$predicates));
		$runtime->execute($query);
	}
}
