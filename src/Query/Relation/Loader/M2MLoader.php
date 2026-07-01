<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation\Loader;

use LogicException;
use ON\Data\Definition\Relation\M2MRelation;
use ON\Data\Definition\Relation\M2MThrough;
use ON\Data\Query\Exception\RelationLoaderException;
use ON\Data\Query\JoinType;
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
		$parent = $branch->getParent();
		$targetIdentity = $branch->requireFields($relation->getCollection()->getPrimaryKey());
		$branch->requireFields($this->publicFieldNames($branch));
		$targetOuterKeyColumns = $branch->requireFields($throughToTarget->getRightFields());
		$parentInnerKeyColumns = $parent->requireFields($parentToThrough->getLeftFields());
		$throughColumns = array_values(array_unique([
			...$throughInnerKeys,
			...$throughOuterKeys,
		]));

		$targetNode = new SingularNode(
			$this->parserFieldNames($branch),
			$targetIdentity,
			$targetOuterKeyColumns,
			$throughOuterKeys,
		);
		$branch->setPublicNode($targetNode);
		$branch->setPublicPayloadChild(self::THROUGH_CONTAINER);

		$throughNode = new M2MThroughNode(
			$throughColumns,
			$throughColumns,
			$throughInnerKeys,
			$parentInnerKeyColumns,
			self::THROUGH_CONTAINER,
			$targetNode,
		);
		$this->selectThroughFields($branch, $runtime, $throughNode);

		return $throughNode;
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
		$branch->requireFields($relation->getCollection()->getPrimaryKey());
		$branch->requireFields($this->publicFieldNames($branch));
		$branch->requireFields($throughToTarget->getRightFields());
		$parent->requireFields($parentToThrough->getLeftFields());

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
		$runtime->continueWith($branch, 'loadData');
	}

	public function loadData(RelationLoadBranch $branch, LoadRuntime $runtime): void
	{
		$references = $branch->getReferenceValues();

		if ($references === []) {
			return;
		}

		$relation = $branch->getRelationRef();
		$definition = $relation->getDefinition();

		if (! $definition instanceof M2MRelation) {
			throw RelationLoaderException::malformedThrough($relation, 'does not use an M2M relation definition.');
		}

		$through = $this->through($relation, $definition);
		$parentToThrough = $definition->getKeyPairing();
		$query = $branch->getQuery();
		$throughSource = $this->throughSource($relation, $query);
		RelationKeyQuery::filterRightByLeftReferences($parentToThrough, $query, $throughSource, $references);
		$this->applySeparateQueryOptions($branch);
		$runtime->execute($branch, $query);
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

	private function selectThroughFields(RelationLoadBranch $branch, LoadRuntime $runtime, M2MThroughNode $throughNode): void
	{
		$relation = $branch->getRelationRef();
		$query = $branch->getQuery();
		$source = $this->throughSource($relation, $query);
		$aliases = [];

		foreach ($this->throughColumns($relation) as $fieldName) {
			$alias = sprintf(
				'__on_data_%s_%s',
				strtolower(preg_replace('/[^a-z0-9_]+/i', '_', implode('_', [...$relation->getPath(), '__through', $fieldName])) ?? 'field'),
				count($aliases),
			);

			if (! $query->getSelections()->hasNamedExpression($alias)) {
				$query->select($source->field($fieldName)->as($alias));
			}

			$aliases[] = $alias;
		}

		$throughNode->setValueAliases($aliases);
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

	/**
	 * @return list<string>
	 */
	private function throughColumns(RelationRef $relation): array
	{
		$definition = $relation->getDefinition();

		if (! $definition instanceof M2MRelation) {
			throw RelationLoaderException::malformedThrough($relation, 'does not use an M2M relation definition.');
		}

		$through = $this->through($relation, $definition);

		return array_values(array_unique([
			...$through->getInnerKeys(),
			...$through->getOuterKeys(),
		]));
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

	/**
	 * @return list<string>
	 */
	private function publicFieldNames(RelationLoadBranch $branch): array
	{
		return array_map(
			static fn (SelectionItem $selection): string => $selection->getSelectionKey(),
			$branch->getSelections()->getPublicItems(),
		);
	}
}
