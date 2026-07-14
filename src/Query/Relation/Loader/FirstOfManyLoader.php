<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation\Loader;

use ON\Data\Query\Exception\RelationLoaderException;
use ON\Data\Query\Expression\StarExpression;
use function ON\Data\Query\query;
use ON\Data\Query\QuerySourceInterface;
use ON\Data\Query\Relation\LoadRuntime;
use ON\Data\Query\Relation\LoadStrategy;
use ON\Data\Query\Relation\RelationKeyQuery;
use ON\Data\Query\Relation\RelationLoadBranch;
use ON\Data\Query\Relation\RelationRef;
use ON\Data\Query\Result\Parser\AbstractNode;
use ON\Data\Query\Result\Parser\SingularNode;
use ON\Data\Query\Selection\SelectionItem;
use ON\Data\Query\Selection\SelectionTag;
use ON\Data\Query\SelectQuery;
use ON\Data\Query\Sort\Sort;
use ON\Data\Query\Sort\SortDirection;
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
		$identity = $branch->requireFields($relationRef->getCollection()->getPrimaryKey());
		$child = $branch->requireFields($parentToChild->getRightFields());
		$parent = $parentBranch->requireFields($parentToChild->getLeftFields());

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
		$this->applySeparateQueryConditions($branch);
		$orderBy = $this->deterministicOrder($branch);
		$runtime->execute($branch, $this->rankedQuery($branch, $query, $orderBy));
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
		$inner = query($childQuery->getCollection());
		$partitionBy = [];

		foreach ($branch->getRelationRef()->getDefinition()->getKeyPairing()->getRightFields() as $fieldName) {
			$partitionBy[] = $inner->field($fieldName);
		}

		// SelectQuery starts with DEFAULT *; keeping it alongside projected columns
		// makes MySQL reject the derived table (Duplicate column name).
		$inner->getSelections()->removeByTag(SelectionTag::DEFAULT);
		$inner->getSelections()->merge(
			$childQuery->getSelections()
				->filter(static fn (SelectionItem $selection): bool => ! $selection->getExpression() instanceof StarExpression)
				->projectTo(from: $childQuery, to: $inner),
		);

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

		$outer->getSelections()->removeByTag(SelectionTag::DEFAULT);
		$outer->getSelections()->merge(
			$inner->getSelections()
				->filterByTag(SelectionTag::COLUMN)
				->filter(static fn (SelectionItem $selection): bool => ! $selection->getExpression() instanceof StarExpression)
				->projectTo(from: $ranked, to: $outer),
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
