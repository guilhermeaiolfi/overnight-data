<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation\Loader;

use ON\Data\Query\Exception\RelationLoaderException;
use ON\Data\Query\Expression\FieldRef;
use function ON\Data\Query\query;
use ON\Data\Query\QuerySourceInterface;
use ON\Data\Query\Relation\LoadRuntime;
use ON\Data\Query\Relation\LoadStrategy;
use ON\Data\Query\Relation\RelationKeyQuery;
use ON\Data\Query\Relation\RelationLoadBranch;
use ON\Data\Query\Relation\RelationRef;
use ON\Data\Query\Result\Parser\AbstractNode;
use ON\Data\Query\Result\Parser\CollectionNode;
use ON\Data\Query\Selection\SelectionItem;
use ON\Data\Query\Selection\SelectionReason;
use ON\Data\Query\SelectQuery;
use ON\Data\Query\Sort\Sort;
use function ON\Data\Query\x;

final class HasManyLoader extends AbstractLoader
{
	private const RANK_ALIAS = '__ondata_rank';
	private const DERIVED_ALIAS = '__ondata_limited_has_many';

	protected function initNode(RelationLoadBranch $branch, LoadRuntime $runtime): AbstractNode
	{
		$relationRef = $branch->getRelationRef();
		$definition = $relationRef->getDefinition();
		$parentToChild = $definition->getKeyPairing();
		$parentBranch = $branch->getParent();
		$identity = $branch->requireFields($relationRef->getCollection()->getPrimaryKey());
		$child = $branch->requireFields($parentToChild->getRightFields());
		$parent = $parentBranch->requireFields($parentToChild->getLeftFields());

		return new CollectionNode(
			$this->parserFieldNames($branch),
			$identity,
			$child,
			$parent,
		);
	}

	public function load(RelationLoadBranch $branch, LoadRuntime $runtime): void
	{
		$relationRef = $branch->getRelationRef();
		$definition = $relationRef->getDefinition();
		$parentToChild = $definition->getKeyPairing();
		$parentBranch = $branch->getParent();
		$branch->requireFields($relationRef->getCollection()->getPrimaryKey());
		$branch->requireFields($parentToChild->getRightFields());
		$parentBranch->requireFields($parentToChild->getLeftFields());

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
		RelationKeyQuery::filterRightByLeftReferences(
			$branch->getRelationRef()->getDefinition()->getKeyPairing(),
			$query,
			$query,
			$references,
		);

		if (! $this->usesLimitOffset($branch)) {
			$this->applySeparateQueryOptions($branch);
			$runtime->execute($branch, $query);

			return;
		}

		$this->applySeparateQueryConditions($branch);
		$orderBy = $this->deterministicOrder($branch);
		$runtime->execute($branch, $this->rankedQuery($branch, $query, $orderBy));
	}

	/**
	 * @return list<string>
	 */
	private function parserFieldNames(RelationLoadBranch $branch): array
	{
		return array_map(
			static fn (SelectionItem $selection): string => $selection->getSelectionKey(),
			$branch->getSelections()->getParserItems(),
		);
	}

	private function usesLimitOffset(RelationLoadBranch $branch): bool
	{
		$selection = $branch->getSelection();

		return $selection->getLimit() !== null || $selection->hasOffset();
	}

	private function applySeparateQueryConditions(RelationLoadBranch $branch): void
	{
		$conditions = $branch->getSelection()->getConditions();

		if ($conditions === []) {
			return;
		}

		$branch->getQuery()->bindConditions(
			$branch->getRelationRef(),
			...$conditions,
		);
	}

	/**
	 * @param list<Sort> $orderBy
	 */
	private function rankedQuery(RelationLoadBranch $branch, SelectQuery $childQuery, array $orderBy): SelectQuery
	{
		$selection = $branch->getSelection();
		$partitionBy = [];
		$inner = query($childQuery->getCollection());
		$relationKeyFields = $branch->getRelationRef()->getDefinition()->getKeyPairing()->getRightFields();

		foreach ($relationKeyFields as $fieldName) {
			$partitionBy[] = $inner->field($fieldName);
		}

		$inner->getSelections()->addProjectedFrom(
			$childQuery->getSelections(),
			from: $childQuery,
			to: $inner,
		);

		foreach ($relationKeyFields as $fieldName) {
			$inner->getSelections()->ensureInternalField($inner->field($fieldName));
		}

		if ($childQuery->getConditions() !== []) {
			$inner->bindConditions($childQuery, ...$childQuery->getConditions());
		}

		$inner->getSelections()->ensureInternalExpression(
			x()->fn()->rowNumber()->over(
				partitionBy: $partitionBy,
				orderBy: array_map(
					static fn (Sort $sort): Sort => $sort->bindTo($inner, from: $childQuery),
					$orderBy,
				),
			),
			self::RANK_ALIAS,
		);

		$ranked = $inner->as(self::DERIVED_ALIAS);
		$outer = query($ranked);

		$outer->getSelections()->addProjectedFrom(
			$inner->getSelections(),
			from: $ranked,
			to: $outer,
		);

		$offset = $selection->getOffset();
		$limit = $selection->getLimit();

		if ($offset > 0) {
			$outer->where($ranked->field(self::RANK_ALIAS)->gt($offset));
		}

		if ($limit !== null) {
			$outer->where($ranked->field(self::RANK_ALIAS)->lte($offset + $limit));
		}

		foreach ($relationKeyFields as $fieldName) {
			$outer->orderBy($ranked->field($fieldName)->asc());
		}

		$outer->orderBy($ranked->field(self::RANK_ALIAS)->asc());

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
			static fn (Sort $sort): Sort => $sort->bindTo($query, from: $relationRef),
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
