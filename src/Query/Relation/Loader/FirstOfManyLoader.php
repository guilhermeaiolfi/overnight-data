<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation\Loader;

use ON\Data\Query\Condition\ConditionInterface;
use ON\Data\Query\Exception\RelationLoaderException;
use ON\Data\Query\Expression\StarExpression;
use function ON\Data\Query\query;
use ON\Data\Query\QuerySourceInterface;
use ON\Data\Query\Relation\LoadRuntime;
use ON\Data\Query\Relation\LoadStrategy;
use ON\Data\Query\Relation\RelationLoadBranch;
use ON\Data\Query\Relation\RelationRef;
use ON\Data\Query\Result\Parser\AbstractNode;
use ON\Data\Query\Result\Parser\SingularNode;
use ON\Data\Query\Selection\SelectionItem;
use ON\Data\Query\Selection\SelectionTag;
use ON\Data\Query\SelectQuery;
use ON\Data\Query\Sort\Sort;
use ON\Data\Query\Sort\SortDirection;
use ON\Data\Query\SourceMap;
use function ON\Data\Query\x;

final class FirstOfManyLoader extends AbstractLoader
{
	private const RANK_ALIAS = '__ondata_rank';
	private const DERIVED_ALIAS = '__ondata_first_of_many';

	public function getDefaultLoadStrategy(): LoadStrategy
	{
		return LoadStrategy::SEPARATE_QUERY;
	}

	protected function initNode(RelationLoadBranch $branch, LoadRuntime $runtime): AbstractNode
	{
		$relationRef = $branch->getRelationRef();
		$definition = $relationRef->getDefinition();
		$parentToChild = $definition->getKeyPairing();
		$parentBranch = $branch->getParent();
		$identity = $this->requireLoadKeys($branch, $relationRef->getCollection()->getPrimaryKey());
		$child = $this->requireLoadKeys($branch, $parentToChild->getRightFields());
		$parent = $this->requireLoadKeys($parentBranch, $parentToChild->getLeftFields());

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
		$parentToChild = $definition->getKeyPairing();
		$parentBranch = $branch->getParent();
		$branch->requireFields($relationRef->getCollection()->getPrimaryKey());
		$branch->requireFields($parentToChild->getRightFields());
		$parentBranch->requireFields($parentToChild->getLeftFields());

		$strategy = $runtime->getLoadStrategy($branch);

		if ($strategy === LoadStrategy::JOIN) {
			throw RelationLoaderException::firstOfManyJoinNotSupported($relationRef);
		}

		if ($branch->getSelection()->getSorts() !== []) {
			throw RelationLoaderException::firstOfManySelectionOrderByNotSupported($relationRef);
		}

		$branch->setJoinedAttachment(false);

		$query = $runtime->createQuery($relationRef->getCollection());

		$runtime->setQueryContext($branch, $query, $query);
		$runtime->continueWith($branch, 'loadData');
	}

	public function loadData(RelationLoadBranch $branch, LoadRuntime $runtime): void
	{
		$this->applySeparateQueryConditions($branch);
		$orderBy = $this->deterministicOrder($branch);
		$this->executeSeparateByReferences(
			$branch,
			$runtime,
			$branch->getRelationRef()->getDefinition()->getKeyPairing(),
			finalize: fn (SelectQuery $query): SelectQuery => $this->rankedQuery($branch, $query, $orderBy),
		);
	}

	public function join(RelationRef $relation): QuerySourceInterface
	{
		throw RelationLoaderException::firstOfManyJoinNotSupported($relation);
	}

	private function applySeparateQueryConditions(RelationLoadBranch $branch): void
	{
		$conditions = $branch->getSelection()->getConditions();

		if ($conditions === []) {
			return;
		}

		$query = $branch->getQuery();
		$query->where(...array_map(
			static fn (ConditionInterface $condition): ConditionInterface => $condition->rebind(
				SourceMap::of($branch->getRelationRef(), $query),
			),
			$conditions,
		));
	}

	/**
	 * @param list<Sort> $orderBy
	 */
	private function rankedQuery(RelationLoadBranch $branch, SelectQuery $childQuery, array $orderBy): SelectQuery
	{
		$inner = $childQuery->copy();
		$partitionBy = [];

		foreach ($branch->getRelationRef()->getDefinition()->getKeyPairing()->getRightFields() as $fieldName) {
			$partitionBy[] = $inner->field($fieldName);
		}

		// Keeping the default star alongside projected columns makes MySQL reject
		// the derived table (Duplicate column name).
		$inner->getSelections()->removeByTag(SelectionTag::DEFAULT);
		$inner->getSelections()->ensureInternalExpression(
			x()->fn()->rowNumber()->over(
				partitionBy: $partitionBy,
				orderBy: array_map(
					static fn (Sort $sort): Sort => $sort->rebind(SourceMap::of($childQuery, $inner)),
					$orderBy,
				),
			),
			self::RANK_ALIAS,
		);

		$ranked = $inner->as(self::DERIVED_ALIAS);
		$outer = query($ranked);

		$outer->getSelections()->removeByTag(SelectionTag::DEFAULT);
		$outer->getSelections()->merge(
			$inner->getSelections()
				->filterByTag(SelectionTag::COLUMN)
				->filter(static fn (SelectionItem $selection): bool => ! $selection->getExpression() instanceof StarExpression)
				->projectDerivedTo($ranked, $outer),
		);

		return $outer->where($ranked->field(self::RANK_ALIAS)->eq(1));
	}

	private function deterministicOrder(RelationLoadBranch $branch): array
	{
		$relationRef = $branch->getRelationRef();
		$definition = $relationRef->getDefinition();
		$orderBy = $definition->getOrderBy();

		if ($orderBy === []) {
			throw RelationLoaderException::firstOfManyOrderRequired($relationRef);
		}

		$query = $branch->getQuery();
		$orderedFields = [];
		$sorts = [];

		foreach ($orderBy as $fieldName => $direction) {
			if (is_int($fieldName)) {
				$fieldName = (string) $direction;
				$direction = SortDirection::ASC->value;
			}

			$fieldName = (string) $fieldName;
			$direction = strtolower((string) $direction);
			$orderedFields[$fieldName] = true;
			$sorts[] = $direction === SortDirection::DESC->value
				? $query->field($fieldName)->desc()
				: $query->field($fieldName)->asc();
		}

		foreach ($relationRef->getCollection()->getPrimaryKey() as $fieldName) {
			if (isset($orderedFields[$fieldName])) {
				continue;
			}

			$sorts[] = $query->field($fieldName)->asc();
		}

		return $sorts;
	}
}
