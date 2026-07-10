<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation;

use ON\Data\Definition\Relation\FirstOfManyRelation;
use ON\Data\Definition\Relation\M2MRelation;
use ON\Data\Definition\Relation\RelationInterface;
use ON\Data\Query\Exception\RelationQueryException;
use ON\Data\Query\JoinType;
use ON\Data\Query\QuerySourceInterface;
use ON\Data\Query\SelectQuery;

/**
 * Plans correlated related queries for EXISTS-style relation predicates.
 *
 * @internal
 */
final class RelationQueryPlanner
{
	public function plan(RelationRef $relation, SelectQuery $parentQuery): SelectQuery
	{
		if ($relation->getQuery() !== $parentQuery) {
			throw RelationQueryException::foreignRelation($relation, $parentQuery);
		}

		$definition = $relation->getDefinition();

		if ($definition instanceof FirstOfManyRelation) {
			throw RelationQueryException::firstOfManyUnsupported($relation);
		}

		$target = $parentQuery->related($definition->getCollection());
		$leftSource = $relation->getParentRelation() ?? $parentQuery;

		if ($definition instanceof M2MRelation) {
			$this->planM2M($relation, $definition, $target, $leftSource);

			return $target;
		}

		$this->planDirect($definition, $target, $leftSource);

		return $target;
	}

	private function planDirect(
		RelationInterface $definition,
		SelectQuery $target,
		QuerySourceInterface $leftSource,
	): void {
		RelationKeyQuery::correlateRightToLeft(
			$definition->getKeyPairing(),
			$target,
			$target,
			$leftSource,
		);
	}

	private function planM2M(
		RelationRef $relation,
		M2MRelation $definition,
		SelectQuery $target,
		QuerySourceInterface $leftSource,
	): void {
		$through = $definition->getThrough();
		$parentToThrough = $definition->getKeyPairing();
		$throughToTarget = $through->getKeyPairing();

		$throughSource = $target->join(
			$through->getCollection(),
			JoinType::INNER,
			implode('.', $relation->getPath()) . '@through',
		);

		RelationKeyQuery::addJoinConditions(
			$throughToTarget->reverse(),
			$throughSource,
			$target,
		);

		RelationKeyQuery::correlateRightToLeft(
			$parentToThrough,
			$target,
			$throughSource,
			$leftSource,
		);
	}
}
