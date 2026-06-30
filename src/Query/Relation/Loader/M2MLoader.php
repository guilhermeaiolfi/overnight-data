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
use ON\Data\Query\Relation\LoadBranch;
use ON\Data\Query\Relation\LoadRuntime;
use ON\Data\Query\Relation\OwnedBranchPlan;
use ON\Data\Query\Relation\RelationRef;
use ON\Data\Query\Result\Parser\AbstractNode;
use ON\Data\Query\Result\Parser\SingularNode;
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

		$throughDefinition = $this->throughDefinition($relation, $definition);
		$branch = $runtime->getCurrentBranch();
		$throughPlan = $this->throughPlan($branch, $throughDefinition);
		$targetIdentity = $branch->requireFields($relation->getCollection()->getPrimaryKey());
		$branch->requireFields($branch->getPublicFields());
		$targetOuterKeys = $branch->requireFields($this->relationKeys($relation, 'outer'));
		$throughOuterKeys = $throughPlan->requireFields($this->throughKeys($relation, $throughDefinition, 'outer'));
		$throughInnerKeys = $throughPlan->requireFields($this->throughKeys($relation, $throughDefinition, 'inner'));
		$parentInnerKeys = $runtime->requireParentFields($this->relationKeys($relation, 'inner'));
		$throughIdentity = $throughPlan->requireFields(array_values(array_unique([
			...$throughInnerKeys,
			...$throughOuterKeys,
		])));

		$targetNode = new SingularNode(
			$branch->getNodeColumns(),
			$targetIdentity,
			$targetOuterKeys,
			$throughOuterKeys,
		);
		$branch->setPublicNode($targetNode);
		$branch->setPublicPayloadChild(self::THROUGH_CONTAINER);

		$throughNode = new M2MThroughNode(
			$throughPlan->getNodeColumns(),
			$throughIdentity,
			$throughInnerKeys,
			$parentInnerKeys,
			self::THROUGH_CONTAINER,
			$targetNode,
		);
		$throughPlan->setNode($throughNode);

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

		$throughDefinition = $this->throughDefinition($relation, $definition);

		if ($throughDefinition->getWhere() !== []) {
			throw RelationLoaderException::throughWhereNotSupported($relation);
		}

		$query = $runtime->createQuery($relation->getCollection());
		$through = $query->join(
			$throughDefinition->getCollection(),
			$definition->isNullable() ? JoinType::LEFT : JoinType::INNER,
			implode('.', $relation->getPath()) . '@through',
		);

		$this->addM2MConditions(
			$through,
			$query,
			$this->relationKeys($relation, 'outer'),
			$this->throughKeys($relation, $throughDefinition, 'outer'),
		);

		$runtime->setJoinedAttachment(false);
		$runtime->setQueryContext($query, $query);
		$this->throughPlan($runtime->getCurrentBranch(), $throughDefinition)->setSource($through);
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

		$throughDefinition = $this->throughDefinition($relation, $definition);
		$through = $this->throughPlan($runtime->getCurrentBranch(), $throughDefinition)->getSource();
		$throughInnerKeys = $this->throughKeys($relation, $throughDefinition, 'inner');
		$query = $runtime->getQuery();

		if (count($throughInnerKeys) === 1) {
			$query->where(
				x()->in(
					$through->field($throughInnerKeys[0]),
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
				$comparisons[] = x()->eq($through->field($fieldName), array_values($values)[$index]);
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

		$throughDefinition = $this->throughDefinition($relation, $definition);

		if ($throughDefinition->getWhere() !== []) {
			throw RelationLoaderException::throughWhereNotSupported($relation);
		}

		$source = $relation->getParentSource();
		$type = $definition->isNullable() ? JoinType::LEFT : JoinType::INNER;
		$query = $relation->getQuery();
		$relationInnerKeys = $this->relationKeys($relation, 'inner');
		$relationOuterKeys = $this->relationKeys($relation, 'outer');
		$throughInnerKeys = $this->throughKeys($relation, $throughDefinition, 'inner');
		$throughOuterKeys = $this->throughKeys($relation, $throughDefinition, 'outer');

		$through = $query->join(
			$throughDefinition->getCollection(),
			$type,
			implode('.', $relation->getPath()) . '@through',
			$source,
		);

		$this->addM2MConditions(
			$through,
			$source,
			$relationInnerKeys,
			$throughInnerKeys,
		);

		$target = $query->join(
			$definition->getCollection(),
			$type,
			implode('.', $relation->getPath()),
			$through,
		);

		$this->addM2MConditions(
			$target,
			$through,
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

	private function throughDefinition(RelationRef $relation, M2MRelation $definition): M2MThrough
	{
		try {
			return $definition->getThrough();
		} catch (LogicException) {
			throw RelationLoaderException::missingThrough($relation);
		}
	}

	private function throughPlan(LoadBranch $branch, M2MThrough $throughDefinition): OwnedBranchPlan
	{
		return $branch->ownedPlan(
			'through',
			$throughDefinition->getCollection(),
			[...$branch->getRelation()->getPath(), '__through'],
		);
	}

	/**
	 * @return non-empty-list<string>
	 */
	private function throughKeys(RelationRef $relation, M2MThrough $throughDefinition, string $side): array
	{
		try {
			$keys = $side === 'inner'
				? $throughDefinition->throughInnerKeys()
				: $throughDefinition->throughOuterKeys();
		} catch (LogicException) {
			throw RelationLoaderException::relationKeysIncomplete($relation);
		}

		if ($keys === []) {
			throw RelationLoaderException::relationKeysIncomplete($relation);
		}

		return $keys;
	}
}
