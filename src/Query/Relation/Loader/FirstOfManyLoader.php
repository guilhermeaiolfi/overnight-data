<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation\Loader;

use ON\Data\Query\Exception\RelationLoaderException;
use ON\Data\Query\QuerySourceInterface;
use ON\Data\Query\Relation\LoadRuntime;
use ON\Data\Query\Relation\LoadStrategy;
use ON\Data\Query\Relation\RelationKeyQuery;
use ON\Data\Query\Relation\RelationLoadBranch;
use ON\Data\Query\Relation\RelationRef;
use ON\Data\Query\Result\Parser\AbstractNode;
use ON\Data\Query\Result\Parser\SingularNode;
use ON\Data\Query\Selection\SelectionItem;
use ON\Data\Query\Sort\SortDirection;

final class FirstOfManyLoader extends AbstractLoader
{
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
		$this->applyDeterministicOrder($branch);
		$runtime->execute($branch, $query);
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

	private function applyDeterministicOrder(RelationLoadBranch $branch): void
	{
		$relationRef = $branch->getRelationRef();
		$definition = $relationRef->getDefinition();
		$orderBy = $definition->getOrderBy();

		if ($orderBy === []) {
			throw RelationLoaderException::firstOfManyOrderRequired($relationRef);
		}

		$query = $branch->getQuery();
		$orderedFields = [];

		foreach ($orderBy as $fieldName => $direction) {
			if (is_int($fieldName)) {
				$fieldName = (string) $direction;
				$direction = SortDirection::ASC->value;
			}

			$fieldName = (string) $fieldName;
			$direction = strtolower((string) $direction);
			$orderedFields[$fieldName] = true;
			$query->orderBy($direction === SortDirection::DESC->value
				? $query->field($fieldName)->desc()
				: $query->field($fieldName)->asc());
		}

		foreach ($relationRef->getCollection()->getPrimaryKey() as $fieldName) {
			if (isset($orderedFields[$fieldName])) {
				continue;
			}

			$query->orderBy($query->field($fieldName)->asc());
		}
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
