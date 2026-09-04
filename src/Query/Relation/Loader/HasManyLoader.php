<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation\Loader;

use ON\Data\Query\Exception\RelationLoaderException;
use ON\Data\Query\Expression\FieldRef;
use ON\Data\Query\QuerySourceInterface;
use ON\Data\Query\Relation\LoadRuntime;
use ON\Data\Query\Relation\LoadStrategy;
use ON\Data\Query\Relation\RelationLoadBranch;
use ON\Data\Query\Relation\RelationRef;
use ON\Data\Query\Result\Parser\AbstractNode;
use ON\Data\Query\Result\Parser\CollectionNode;
use ON\Data\Query\SelectQuery;
use ON\Data\Query\Sort\Sort;
use ON\Data\Query\SourceMap;

final class HasManyLoader extends AbstractLoader
{
	private const DERIVED_ALIAS = '__ondata_limited_has_many';

	protected function initNode(RelationLoadBranch $branch, LoadRuntime $runtime): AbstractNode
	{
		$relationRef = $branch->getRelationRef();
		$pairing = $relationRef->getDefinition()->getKeyPairing();

		return new CollectionNode(
			$branch->getRemapColumns(),
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

		if ($strategy === LoadStrategy::JOIN) {
			if ($this->usesLimitOffset($branch)) {
				throw RelationLoaderException::hasManyLimitOffsetJoinNotSupported($relationRef);
			}

			$this->assertNoJoinedSelectionOptions($branch);
			$queryRelation = $runtime->getQueryRelation($branch);
			$source = $this->join($queryRelation);
			$runtime->setQueryContext($branch, $queryRelation->getQuery(), $source, $queryRelation);
		} else {
			$query = $runtime->createQuery($relationRef->getCollection());
			$runtime->setQueryContext($branch, $query, $query);
		}

		$branch->requireFields($relationRef->getCollection()->getPrimaryKey());
		$branch->requireFields($parentToChild->getRightFields());
		$parentBranch->requireFields($parentToChild->getLeftFields());

		if ($strategy !== LoadStrategy::JOIN) {
			$runtime->continueWith($branch, 'loadData');
		}
	}

	public function loadData(RelationLoadBranch $branch, LoadRuntime $runtime): void
	{
		$pairing = $branch->getRelationRef()->getDefinition()->getKeyPairing();

		if (! $this->usesLimitOffset($branch)) {
			$this->applySeparateQueryOptions($branch);
			$this->executeSeparateByReferences($branch, $runtime, $pairing);

			return;
		}

		$this->applySeparateQueryConditions($branch);
		$orderBy = $this->deterministicOrder($branch);
		$this->executeSeparateByReferences(
			$branch,
			$runtime,
			$pairing,
			finalize: fn (SelectQuery $query): SelectQuery => $this->rankedQuery($branch, $query, $orderBy),
		);
	}

	private function usesLimitOffset(RelationLoadBranch $branch): bool
	{
		$selection = $branch->getSelection();

		return $selection->getLimit() !== null || $selection->hasOffset();
	}

	/**
	 * @param list<Sort> $orderBy
	 */
	private function rankedQuery(RelationLoadBranch $branch, SelectQuery $childQuery, array $orderBy): SelectQuery
	{
		$selection = $branch->getSelection();
		$relationKeyFields = $branch->getRelationRef()->getDefinition()->getKeyPairing()->getRightFields();
		$partitioned = PartitionedRelationQuery::wrap(
			$childQuery,
			$relationKeyFields,
			$orderBy,
			self::DERIVED_ALIAS,
		);
		$outer = $partitioned->getQuery();
		$offset = $selection->getOffset();
		$limit = $selection->getLimit();

		if ($offset > 0) {
			$outer->where($partitioned->rank()->gt($offset));
		}

		if ($limit !== null) {
			$outer->where($partitioned->rank()->lte($offset + $limit));
		}

		foreach ($relationKeyFields as $fieldName) {
			$outer->orderBy($partitioned->partitionField($fieldName)->asc());
		}

		$outer->orderBy($partitioned->rank()->asc());

		return $outer;
	}

	/**
	 * @return list<Sort>
	 */
	private function deterministicOrder(RelationLoadBranch $branch): array
	{
		$relationRef = $branch->getRelationRef();
		$selection = $branch->getSelection();
		$orderBy = $selection->getSorts();
		$query = $branch->getQuery();

		if ($orderBy === []) {
			throw RelationLoaderException::hasManyLimitOffsetOrderRequired($relationRef);
		}

		$orderedPrimaryKeys = [];
		$sorts = array_map(
			static fn (Sort $sort): Sort => $sort->rebind(SourceMap::of($relationRef, $query)),
			$orderBy,
		);

		foreach ($orderBy as $sort) {
			$expression = $sort->getExpression();

			if (! $expression instanceof FieldRef || $expression->getSource() !== $relationRef) {
				continue;
			}

			$orderedPrimaryKeys[$expression->getField()->getName()] = true;
		}

		foreach ($relationRef->getCollection()->getPrimaryKey() as $fieldName) {
			if (isset($orderedPrimaryKeys[$fieldName])) {
				continue;
			}

			$sorts[] = $query->field($fieldName)->asc();
		}

		return $sorts;
	}

	public function join(RelationRef $relation): QuerySourceInterface
	{
		if ($relation->getLimit() !== null || $relation->hasOffset()) {
			throw RelationLoaderException::hasManyLimitOffsetJoinNotSupported($relation);
		}

		return parent::join($relation);
	}
}
