<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation\Loader;

use ON\Data\Query\Exception\RelationLoaderException;
use ON\Data\Query\Expression\FieldRef;
use ON\Data\Query\Expression\RawSqlExpression;
use ON\Data\Query\QuerySourceInterface;
use ON\Data\Query\Relation\LoadRuntime;
use ON\Data\Query\Relation\LoadStrategy;
use ON\Data\Query\Relation\RelationKeyQuery;
use ON\Data\Query\Relation\RelationLoadBranch;
use ON\Data\Query\Relation\RelationRef;
use ON\Data\Query\Result\Parser\AbstractNode;
use ON\Data\Query\Result\Parser\SingularNode;
use ON\Data\Query\Selection\SelectionItem;
use ON\Data\Query\SelectQuery;
use ON\Data\Query\Sort\Sort;
use ON\Data\Query\Sort\SortDirection;
use function ON\Data\Query\x;

final class FirstOfManyLoader extends AbstractLoader
{
	private const ROW_NUMBER_ALIAS = '__ondata_row_number';

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
		$orderBy = $this->applyDeterministicOrder($branch);
		$query->select($this->rowNumberExpression($branch, $orderBy)->as(self::ROW_NUMBER_ALIAS));
		$executable = $this->rankedOuterQuery($branch, $runtime, $query);

		$runtime->setQueryContext($branch, $executable, $executable);

		$runtime->execute($branch, $executable);
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

		$branch->getQuery()->adoptConditions(
			$branch->getRelationRef(),
			...$conditions,
		);
	}

	/**
	 * @return non-empty-list<\ON\Data\Query\Sort\Sort>
	 */
	private function applyDeterministicOrder(RelationLoadBranch $branch): array
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
			$query->orderBy($sorts[array_key_last($sorts)]);
		}

		foreach ($relationRef->getCollection()->getPrimaryKey() as $fieldName) {
			if (isset($orderedFields[$fieldName])) {
				continue;
			}

			$sorts[] = $query->field($fieldName)->asc();
			$query->orderBy($sorts[array_key_last($sorts)]);
		}

		return $sorts;
	}

	/**
	 * @param non-empty-list<Sort> $orderBy
	 */
	private function rowNumberExpression(RelationLoadBranch $branch, array $orderBy): RawSqlExpression
	{
		$relationRef = $branch->getRelationRef();
		$query = $branch->getQuery();
		$partitionBy = array_map(
			fn (string $field): string => $this->qualifiedColumn($query, $field),
			$relationRef->getDefinition()->getKeyPairing()->getRightFields(),
		);
		$orderSql = array_map(
			fn (Sort $sort): string => $this->sortSql($query, $sort),
			$orderBy,
		);

		return x()->rawSql(sprintf(
			'ROW_NUMBER() OVER (PARTITION BY %s ORDER BY %s)',
			implode(', ', $partitionBy),
			implode(', ', $orderSql),
		));
	}

	private function rankedOuterQuery(
		RelationLoadBranch $branch,
		LoadRuntime $runtime,
		SelectQuery $ranked,
	): SelectQuery {
		$outer = $runtime->createQuery($branch->getRelationRef()->getCollection())->from($ranked);

		foreach ($ranked->getSelections()->getExplicit() as $selection) {
			$key = $selection->getSelectionKey();

			if ($key === self::ROW_NUMBER_ALIAS) {
				continue;
			}

			$outer->select(x()->rawSql($key)->as($key));
		}

		$outer->where(x()->eq(x()->rawSql(self::ROW_NUMBER_ALIAS), 1));

		return $outer;
	}

	private function sortSql(SelectQuery $query, Sort $sort): string
	{
		$expression = $sort->getExpression();

		if (! $expression instanceof FieldRef) {
			return $expression->getSelectionKey() . ' ' . strtoupper($sort->getDirection()->value);
		}

		return $this->qualifiedColumn($query, $expression->getName()) . ' ' . strtoupper($sort->getDirection()->value);
	}

	private function qualifiedColumn(SelectQuery $query, string $fieldName): string
	{
		return 'q0.' . $query->field($fieldName)->getField()->getColumn();
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
}
