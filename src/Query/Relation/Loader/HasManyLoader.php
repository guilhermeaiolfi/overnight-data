<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation\Loader;

use ON\Data\Query\Relation\LoadRuntime;
use ON\Data\Query\Relation\LoadStrategy;
use ON\Data\Query\Relation\RelationRef;
use ON\Data\Query\Result\Parser\AbstractNode;
use ON\Data\Query\Result\Parser\CollectionNode;
use function ON\Data\Query\x;

final class HasManyLoader extends AbstractLoader
{
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
		$node = new CollectionNode(
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
		$strategy = $runtime->getLoadStrategy($this->getDefaultLoadStrategy());

		if ($strategy === LoadStrategy::JOIN) {
			$queryRelation = $runtime->getQueryRelation();
			$source = $this->join($queryRelation);

			$runtime->setQueryContext($queryRelation->getQuery(), $source, $queryRelation);

			return;
		}

		$query = $runtime->createQuery($relation->getCollection());

		$runtime->setQueryContext($query, $query);
		$runtime->nextPass('loadData');
	}

	public function loadData(RelationRef $relation, LoadRuntime $runtime): void
	{
		$references = $runtime->getReferenceValues();

		if ($references === []) {
			return;
		}

		$query = $runtime->getQuery();
		$childFields = $this->relationKeys($relation, 'outer');

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
