<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation\Loader;

use LogicException;
use ON\Data\Definition\Relation\M2MRelation;
use ON\Data\Definition\Relation\M2MThrough;
use ON\Data\Query\Exception\RelationLoaderException;
use ON\Data\Query\JoinType;
use ON\Data\Query\QuerySourceInterface;
use ON\Data\Query\Relation\ColumnRole;
use ON\Data\Query\Relation\LoadRuntime;
use ON\Data\Query\Relation\LoadStrategy;
use ON\Data\Query\Relation\RelationKeyQuery;
use ON\Data\Query\Relation\RelationLoadBranch;
use ON\Data\Query\Relation\RelationRef;
use ON\Data\Query\Result\Parser\AbstractNode;
use ON\Data\Query\Result\Parser\SingularNode;
use ON\Data\Query\SelectQuery;

final class M2MLoader extends AbstractLoader
{
	private const THROUGH_CONTAINER = '__target';

	protected function initNode(RelationLoadBranch $branch, LoadRuntime $runtime): AbstractNode
	{
		$relation = $branch->getRelationRef();
		$definition = $relation->getDefinition();

		if (! $definition instanceof M2MRelation) {
			throw RelationLoaderException::malformedThrough($relation, 'does not use an M2M relation definition.');
		}

		$through = $this->through($relation, $definition);
		$parentToThrough = $definition->getKeyPairing();
		$throughToTarget = $through->getKeyPairing();
		$throughInnerKeys = $through->getInnerKeys();
		$throughOuterKeys = $through->getOuterKeys();
		$throughFieldNames = array_values(array_unique([
			...$throughInnerKeys,
			...$throughOuterKeys,
		]));
		$throughRemap = $this->allocateThroughColumns($branch, $runtime, $throughFieldNames);

		$targetNode = new SingularNode(
			$branch->getRemapColumns(),
			$relation->getCollection()->getPrimaryKey(),
			$throughToTarget->getRightFields(),
			$throughOuterKeys,
		);
		$branch->setPublicPayloadChild(self::THROUGH_CONTAINER);

		return new M2MThroughNode(
			$throughRemap,
			$throughFieldNames,
			$throughInnerKeys,
			$parentToThrough->getLeftFields(),
			self::THROUGH_CONTAINER,
			$targetNode,
		);
	}

	public function load(RelationLoadBranch $branch, LoadRuntime $runtime): void
	{
		$relation = $branch->getRelationRef();
		$this->assertSupportedRelationPath($relation);
		$this->assertSupportedRelationConstraints($relation);

		if ($runtime->getLoadStrategy($branch) === LoadStrategy::JOIN) {
			throw RelationLoaderException::joinedLoadingNotImplemented($relation);
		}

		$definition = $relation->getDefinition();

		if (! $definition instanceof M2MRelation) {
			throw RelationLoaderException::malformedThrough($relation, 'does not use an M2M relation definition.');
		}

		$through = $this->through($relation, $definition);
		$parentToThrough = $definition->getKeyPairing();
		$throughToTarget = $through->getKeyPairing();
		$parent = $branch->getParent();

		if ($through->getWhere() !== []) {
			throw RelationLoaderException::throughWhereNotSupported($relation);
		}

		$query = $runtime->createQuery($relation->getCollection());
		$throughSource = $query->join(
			$through->getCollection(),
			$definition->isNullable() ? JoinType::LEFT : JoinType::INNER,
			implode('.', $relation->getPath()) . '@through',
		);

		RelationKeyQuery::addJoinConditions($throughToTarget->reverse(), $throughSource, $query);

		$branch->setJoinedAttachment(false);
		$runtime->setQueryContext($branch, $query, $query);
		$branch->requireFields($relation->getCollection()->getPrimaryKey());
		$branch->requireFields($throughToTarget->getRightFields());
		$parent->requireFields($parentToThrough->getLeftFields());
		$runtime->continueWith($branch, $this->loadData(...));
	}

	public function loadData(RelationLoadBranch $branch, LoadRuntime $runtime): void
	{
		$relation = $branch->getRelationRef();
		$definition = $relation->getDefinition();

		if (! $definition instanceof M2MRelation) {
			throw RelationLoaderException::malformedThrough($relation, 'does not use an M2M relation definition.');
		}

		$this->applySeparateQueryOptions($branch);
		$this->executeSeparateByReferences(
			$branch,
			$runtime,
			$definition->getKeyPairing(),
			rightSource: fn (SelectQuery $query): QuerySourceInterface => $this->throughSource($relation, $query),
		);
	}

	public function join(RelationRef $relation): QuerySourceInterface
	{
		$this->assertSupportedRelationPath($relation);
		$this->assertSupportedRelationConstraints($relation);

		$definition = $relation->getDefinition();

		if (! $definition instanceof M2MRelation) {
			throw RelationLoaderException::malformedThrough($relation, 'does not use an M2M relation definition.');
		}

		$through = $this->through($relation, $definition);
		$parentToThrough = $definition->getKeyPairing();
		$throughToTarget = $through->getKeyPairing();

		if ($through->getWhere() !== []) {
			throw RelationLoaderException::throughWhereNotSupported($relation);
		}

		$source = $relation->getParentSource();
		$type = $definition->isNullable() ? JoinType::LEFT : JoinType::INNER;
		$query = $relation->getQuery();

		$throughSource = $query->join(
			$through->getCollection(),
			$type,
			implode('.', $relation->getPath()) . '@through',
			$source,
		);

		RelationKeyQuery::addJoinConditions($parentToThrough, $throughSource, $source);

		$target = $query->join(
			$definition->getCollection(),
			$type,
			implode('.', $relation->getPath()),
			$throughSource,
		);

		RelationKeyQuery::addJoinConditions($throughToTarget, $target, $throughSource);

		return $target;
	}

	private function through(RelationRef $relation, M2MRelation $definition): M2MThrough
	{
		try {
			return $definition->getThrough();
		} catch (LogicException) {
			throw RelationLoaderException::missingThrough($relation);
		}
	}

	/**
	 * @param list<string> $fieldNames
	 * @return array<string, string> field name => SQL alias
	 */
	private function allocateThroughColumns(
		RelationLoadBranch $branch,
		LoadRuntime $runtime,
		array $fieldNames,
	): array {
		$relation = $branch->getRelationRef();
		$source = $this->throughSource($relation, $branch->getQuery());
		$remap = [];

		foreach ($fieldNames as $fieldName) {
			$remap[$fieldName] = $runtime->selectAuxiliaryColumn(
				$branch,
				$source->field($fieldName),
				ColumnRole::JoinKey,
			);
		}

		return $remap;
	}

	private function throughSource(RelationRef $relation, SelectQuery $query): QuerySourceInterface
	{
		$name = $this->throughJoinName($relation);

		foreach ($query->getJoins() as $join) {
			if ($join->getName() === $name) {
				return $join;
			}
		}

		throw RelationLoaderException::loadingNotImplemented($relation);
	}

	private function throughJoinName(RelationRef $relation): string
	{
		return implode('.', $relation->getPath()) . '@through';
	}
}
