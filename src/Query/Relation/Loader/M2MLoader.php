<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation\Loader;

use LogicException;
use ON\Data\Definition\Relation\M2MRelation;
use ON\Data\Definition\Relation\M2MThrough;
use ON\Data\Query\Exception\RelationLoaderException;
use ON\Data\Query\Join;
use ON\Data\Query\JoinType;
use ON\Data\Query\QuerySourceInterface;
use ON\Data\Query\Relation\LoadRuntime;
use ON\Data\Query\Relation\RelationRef;
use ON\Data\Query\Result\Parser\AbstractNode;
use ON\Data\Query\Result\Parser\SingularNode;
use ON\Data\Query\SelectQuery;
use function ON\Data\Query\x;

final class M2MLoader extends AbstractLoader
{
	private const THROUGH_CONTAINER = '__target';

	protected function initNode(RelationRef $relation, LoadRuntime $runtime): AbstractNode
	{
		$definition = $relation->getRelation();

		if (! $definition instanceof M2MRelation) {
			throw RelationLoaderException::malformedThrough($relation, 'does not use an M2M relation definition.');
		}

		$through = $this->through($relation, $definition);
		$relationInnerKeys = $definition->getInnerKeys();
		$relationOuterKeys = $definition->getOuterKeys();
		$throughInnerKeys = $through->getInnerKeys();
		$throughOuterKeys = $through->getOuterKeys();
		$current = $runtime->getCurrentBranch();
		$parent = $runtime->requireParentBranch();
		$targetIdentity = $current->requireFields($relation->getCollection()->getPrimaryKey());
		$current->requireFields($current->getPublicFields());
		$targetOuterKeyColumns = $current->requireFields($relationOuterKeys);
		$parentInnerKeyColumns = $parent->requireFields($relationInnerKeys);
		$throughColumns = array_values(array_unique([
			...$throughInnerKeys,
			...$throughOuterKeys,
		]));

		$targetNode = new SingularNode(
			$runtime->getNodeColumns(),
			$targetIdentity,
			$targetOuterKeyColumns,
			$throughOuterKeys,
		);
		$current->setPublicNode($targetNode);
		$current->setPublicPayloadChild(self::THROUGH_CONTAINER);

		$throughNode = new M2MThroughNode(
			$throughColumns,
			$throughColumns,
			$throughInnerKeys,
			$parentInnerKeyColumns,
			self::THROUGH_CONTAINER,
			$targetNode,
		);
		$this->selectThroughFields($relation, $runtime, $throughNode);

		return $throughNode;
	}

	public function load(RelationRef $relation, LoadRuntime $runtime): void
	{
		$this->assertSupportedRelationPath($relation);
		$this->assertSupportedRelationConstraints($relation);

		$definition = $relation->getRelation();

		if (! $definition instanceof M2MRelation) {
			throw RelationLoaderException::malformedThrough($relation, 'does not use an M2M relation definition.');
		}

		$through = $this->through($relation, $definition);
		$current = $runtime->getCurrentBranch();
		$parent = $runtime->requireParentBranch();
		$current->requireFields($relation->getCollection()->getPrimaryKey());
		$current->requireFields($current->getPublicFields());
		$current->requireFields($definition->getOuterKeys());
		$parent->requireFields($definition->getInnerKeys());

		if ($through->getWhere() !== []) {
			throw RelationLoaderException::throughWhereNotSupported($relation);
		}

		$relationOuterKeys = $definition->getOuterKeys();
		$throughOuterKeys = $through->getOuterKeys();
		$query = $runtime->createQuery($relation->getCollection());
		$throughSource = $query->join(
			$through->getCollection(),
			$definition->isNullable() ? JoinType::LEFT : JoinType::INNER,
			implode('.', $relation->getPath()) . '@through',
		);

		$this->addM2MConditions(
			$throughSource,
			$query,
			$relationOuterKeys,
			$throughOuterKeys,
		);

		$runtime->setJoinedAttachment(false);
		$runtime->setQueryContext($query, $query);
		$runtime->nextPass('loadData');
	}

	public function loadData(RelationRef $relation, LoadRuntime $runtime): void
	{
		$references = $runtime->getReferenceValues();

		if ($references === []) {
			return;
		}

		$definition = $relation->getRelation();

		if (! $definition instanceof M2MRelation) {
			throw RelationLoaderException::malformedThrough($relation, 'does not use an M2M relation definition.');
		}

		$through = $this->through($relation, $definition);
		$query = $runtime->getQuery();
		$throughSource = $this->throughSource($relation, $query);
		$throughInnerKeys = $through->getInnerKeys();

		if (count($throughInnerKeys) === 1) {
			$query->where(
				x()->in(
					$throughSource->field($throughInnerKeys[0]),
					array_map(static fn (array $values) => array_values($values)[0], $references),
				),
			);

			$runtime->execute($query);

			return;
		}

		$predicates = [];

		foreach ($references as $values) {
			$comparisons = [];

			foreach ($throughInnerKeys as $index => $fieldName) {
				$comparisons[] = x()->eq($throughSource->field($fieldName), array_values($values)[$index]);
			}

			$predicates[] = x()->and(...$comparisons);
		}

		$query->where(x()->or(...$predicates));
		$runtime->execute($query);
	}

	public function join(RelationRef $relation): QuerySourceInterface
	{
		$this->assertSupportedRelationPath($relation);
		$this->assertSupportedRelationConstraints($relation);

		$definition = $relation->getRelation();

		if (! $definition instanceof M2MRelation) {
			throw RelationLoaderException::malformedThrough($relation, 'does not use an M2M relation definition.');
		}

		$through = $this->through($relation, $definition);

		if ($through->getWhere() !== []) {
			throw RelationLoaderException::throughWhereNotSupported($relation);
		}

		$source = $relation->getParentSource();
		$type = $definition->isNullable() ? JoinType::LEFT : JoinType::INNER;
		$query = $relation->getQuery();
		$relationInnerKeys = $definition->getInnerKeys();
		$relationOuterKeys = $definition->getOuterKeys();
		$throughInnerKeys = $through->getInnerKeys();
		$throughOuterKeys = $through->getOuterKeys();

		$throughSource = $query->join(
			$through->getCollection(),
			$type,
			implode('.', $relation->getPath()) . '@through',
			$source,
		);

		$this->addM2MConditions(
			$throughSource,
			$source,
			$relationInnerKeys,
			$throughInnerKeys,
		);

		$target = $query->join(
			$definition->getCollection(),
			$type,
			implode('.', $relation->getPath()),
			$throughSource,
		);

		$this->addM2MConditions(
			$target,
			$throughSource,
			$throughOuterKeys,
			$relationOuterKeys,
		);

		return $target;
	}

	/**
	 * @param list<string> $sourceKeys
	 * @param list<string> $targetKeys
	 */
	private function addM2MConditions(
		Join $join,
		QuerySourceInterface $source,
		array $sourceKeys,
		array $targetKeys,
	): void {
		foreach ($sourceKeys as $index => $sourceKey) {
			$join->on(
				x()->eq($source->field($sourceKey), $join->field($targetKeys[$index])),
			);
		}
	}

	private function through(RelationRef $relation, M2MRelation $definition): M2MThrough
	{
		try {
			return $definition->getThrough();
		} catch (LogicException) {
			throw RelationLoaderException::missingThrough($relation);
		}
	}

	private function selectThroughFields(RelationRef $relation, LoadRuntime $runtime, M2MThroughNode $throughNode): void
	{
		$query = $runtime->getQuery();
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
		$definition = $relation->getRelation();

		if (! $definition instanceof M2MRelation) {
			throw RelationLoaderException::malformedThrough($relation, 'does not use an M2M relation definition.');
		}

		$through = $this->through($relation, $definition);

		return array_values(array_unique([
			...$through->getInnerKeys(),
			...$through->getOuterKeys(),
		]));
	}
}
