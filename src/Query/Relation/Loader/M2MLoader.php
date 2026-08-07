<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation\Loader;

use LogicException;
use ON\Data\Definition\Relation\M2MRelation;
use ON\Data\Definition\Relation\M2MThrough;
use ON\Data\Query\Exception\RelationLoaderException;
use ON\Data\Query\Expression\AliasedExpression;
use ON\Data\Query\Expression\FieldRef;
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
use ON\Data\Query\Selection\SelectionTag;
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
		$targetIdentity = $this->requireLoadKeys($branch, $relation->getCollection()->getPrimaryKey());
		$this->requireLoadKeys($branch, $this->publicFieldNames($branch));
		$targetOuterKeyColumns = $this->requireLoadKeys($branch, $throughToTarget->getRightFields());
		$parentInnerKeyColumns = $this->requireLoadKeys($parent, $parentToThrough->getLeftFields());
		$throughFieldNames = array_values(array_unique([
			...$throughInnerKeys,
			...$throughOuterKeys,
		]));
		$throughAliasesByField = $this->allocateThroughAliases($branch, $throughFieldNames);
		$throughColumns = array_values($throughAliasesByField);
		$throughInnerLoadKeys = array_map(
			static fn (string $field): string => $throughAliasesByField[$field],
			$throughInnerKeys,
		);
		$throughOuterLoadKeys = array_map(
			static fn (string $field): string => $throughAliasesByField[$field],
			$throughOuterKeys,
		);

		$targetNode = new SingularNode(
			$this->columnSelectionKeys($branch),
			$targetIdentity,
			$targetOuterKeyColumns,
			$throughOuterLoadKeys,
		);
		$branch->setPublicNode($targetNode);
		$branch->setPublicPayloadChild(self::THROUGH_CONTAINER);

		return new M2MThroughNode(
			$throughColumns,
			$throughColumns,
			$throughInnerLoadKeys,
			$parentInnerKeyColumns,
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
	 * @return array<string, string> field name → load alias
	 */
	private function allocateThroughAliases(
		RelationLoadBranch $branch,
		array $fieldNames,
	): array {
		$relation = $branch->getRelationRef();
		$query = $branch->getQuery();
		$source = $this->throughSource($relation, $query);
		$aliasesByField = [];

		foreach ($fieldNames as $fieldName) {
			$alias = sprintf(
				'__on_data_%s_%s',
				strtolower(preg_replace('/[^a-z0-9_]+/i', '_', implode('_', [...$relation->getPath(), '__through', $fieldName])) ?? 'field'),
				count($aliasesByField),
			);

			if (! $query->getSelections()->hasNamedExpression($alias)) {
				$query->select($source->field($fieldName)->as($alias));
			}

			$aliasesByField[$fieldName] = $alias;
		}

		return $aliasesByField;
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
	private function publicFieldNames(RelationLoadBranch $branch): array
	{
		return array_map(
			static function (SelectionItem $selection): string {
				$expression = $selection->getExpression();

				if ($expression instanceof AliasedExpression) {
					$expression = $expression->getExpression();
				}

				if ($expression instanceof FieldRef) {
					return $expression->getField()->getName();
				}

				return $selection->getSelectionKey();
			},
			$branch->getSelections()->getByTag(SelectionTag::PUBLIC),
		);
	}
}
