<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation\Loader;

use LogicException;
use ON\Data\Definition\Relation\M2MRelation;
use ON\Data\Definition\Relation\M2MThrough;
use ON\Data\Query\Exception\RelationLoaderException;
use ON\Data\Query\JoinType;
use ON\Data\Query\QuerySourceInterface;
use ON\Data\Query\Relation\KeyEncoder;
use ON\Data\Query\Relation\LoadRuntime;
use ON\Data\Query\Relation\LoadStrategy;
use ON\Data\Query\Relation\RelationKeyQuery;
use ON\Data\Query\Relation\RelationLoadBranch;
use ON\Data\Query\Relation\RelationRef;
use ON\Data\Query\Relation\ResultRecord;
use ON\Data\Query\SelectQuery;

final class M2MLoader extends AbstractLoader
{
	public function load(RelationLoadBranch $branch, LoadRuntime $runtime): void
	{
		$relation = $branch->getRelationRef();
		$this->assertSupportedRelationPath($relation);
		$this->assertSupportedRelationConstraints($relation);

		if ($runtime->getLoadStrategy($branch) === LoadStrategy::JOIN) {
			throw RelationLoaderException::joinedLoadingNotImplemented($relation);
		}

		$definition = $this->definition($relation);

		$through = $this->through($relation, $definition);
		$parentToThrough = $definition->getKeyPairing();
		$throughToTarget = $through->getKeyPairing();
		$parent = $branch->getParent();

		$query = $runtime->createQuery($relation->getCollection());
		$throughSource = $query->join(
			$through->getCollection(),
			$definition->isNullable() ? JoinType::LEFT : JoinType::INNER,
			$this->throughJoinName($relation, $query),
		);

		RelationKeyQuery::addJoinConditions($throughToTarget->reverse(), $throughSource, $query);

		$runtime->setQueryContext($branch, $query, $query);

		$throughInnerAliases = [];

		foreach ($through->getInnerKeys() as $fieldName) {
			$throughInnerAliases[] = $runtime->selectAuxiliaryColumn($branch, $throughSource->field($fieldName));
		}

		$parentFields = $parentToThrough->getLeftFields();
		$runtime->collectWith(
			$branch,
			static function (RelationLoadBranch $branch, array $row) use ($parentFields, $throughInnerAliases): ?ResultRecord {
				$record = $branch->ingestRecord($branch->extractRow($row));

				if ($record === null) {
					return null;
				}

				$throughTuple = KeyEncoder::extractTuple($throughInnerAliases, $row);

				if ($throughTuple === null) {
					return $record;
				}

				$parent = $branch->getParent();

				foreach ($parent->findRecordsByKey($parentFields, $throughTuple) as $parentRecord) {
					$parent->attachChild($parentRecord, $branch, $record);
				}

				return $record;
			},
		);
		$branch->requireFields($relation->getCollection()->getPrimaryKey());
		$parent->requireFields($parentFields);
		$runtime->continueWith($branch, function (RelationLoadBranch $branch, LoadRuntime $runtime) use ($throughSource, $parentToThrough): void {
			$this->applySeparateQueryOptions($branch);
			$this->executeSeparateByReferences($branch, $runtime, $parentToThrough, rightSource: static fn () => $throughSource);
		});
	}

	public function join(RelationRef $relation): QuerySourceInterface
	{
		$this->assertSupportedRelationPath($relation);
		$this->assertSupportedRelationConstraints($relation);

		$definition = $this->definition($relation);

		$through = $this->through($relation, $definition);
		$parentToThrough = $definition->getKeyPairing();
		$throughToTarget = $through->getKeyPairing();

		$source = $relation->getParentSource();
		$type = $definition->isNullable() ? JoinType::LEFT : JoinType::INNER;
		$query = $relation->getQuery();

		$throughSource = $query->join(
			$through->getCollection(),
			$type,
			$this->throughJoinName($relation, $query),
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
			$through = $definition->getThrough();
		} catch (LogicException) {
			throw RelationLoaderException::missingThrough($relation);
		}
		if ($through->getWhere() !== []) {
			throw RelationLoaderException::throughWhereNotSupported($relation);
		}

		return $through;
	}

	private function definition(RelationRef $relation): M2MRelation
	{
		$definition = $relation->getDefinition();
		if (! $definition instanceof M2MRelation) {
			throw RelationLoaderException::malformedThrough($relation, 'does not use an M2M relation definition.');
		}

		return $definition;
	}

	private function throughJoinName(RelationRef $relation, SelectQuery $query): string
	{
		$base = implode('.', $relation->getPath()) . '@through';
		$used = [];
		foreach ($query->getJoins() as $join) {
			$used[$join->getName()] = true;
		}

		$name = $base;
		for ($suffix = 2; isset($used[$name]); ++$suffix) {
			$name = $base . '_' . $suffix;
		}

		return $name;
	}
}
