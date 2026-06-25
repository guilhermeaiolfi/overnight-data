<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation\Loader;

use LogicException;
use ON\Data\Query\Exception\RelationLoaderException;
use ON\Data\Query\Join;
use ON\Data\Query\JoinType;
use ON\Data\Query\QuerySourceInterface;
use ON\Data\Query\Relation\LoadRuntime;
use ON\Data\Query\Relation\RelationRef;
use function ON\Data\Query\x;

abstract class AbstractLoader implements LoaderInterface
{
	public function join(RelationRef $relation): QuerySourceInterface
	{
		$this->assertSupportedRelationPath($relation);
		$this->assertSupportedRelationConstraints($relation);

		$definition = $relation->getRelation();
		$source = $relation->getParentSource();
		$innerKeys = $this->relationKeys($relation, 'inner');
		$outerKeys = $this->relationKeys($relation, 'outer');
		$this->assertMatchingKeys($relation, $innerKeys, $outerKeys);
		$join = $relation->getQuery()->join(
			$definition->getCollection(),
			$definition->isNullable() ? JoinType::LEFT : JoinType::INNER,
			implode('.', $relation->getPath()),
			$source,
		);

		$this->addKeyConditions(
			$join,
			$source,
			$innerKeys,
			$outerKeys,
			$relation,
		);

		return $join;
	}

	public function load(RelationRef $relation, LoadRuntime $runtime): void
	{
		throw RelationLoaderException::loadingNotImplemented($relation);
	}

	protected function assertSupportedRelationPath(RelationRef $relation): void
	{
		if ($relation->getParentRelation() !== null) {
			throw RelationLoaderException::nestedJoinNotSupported($relation);
		}
	}

	protected function assertSupportedRelationConstraints(RelationRef $relation): void
	{
		$definition = $relation->getRelation();

		if ($definition->getWhere() !== []) {
			throw RelationLoaderException::relationWhereNotSupported($relation);
		}

		if ($definition->getOrderBy() !== []) {
			throw RelationLoaderException::relationOrderByNotSupported($relation);
		}
	}

	/**
	 * @param non-empty-list<string> $sourceKeys
	 * @param non-empty-list<string> $targetKeys
	 */
	protected function addKeyConditions(
		Join $join,
		QuerySourceInterface $source,
		array $sourceKeys,
		array $targetKeys,
		RelationRef $relation,
	): void {
		if ($sourceKeys === [] || $targetKeys === []) {
			throw RelationLoaderException::relationKeysIncomplete($relation);
		}

		if (count($sourceKeys) !== count($targetKeys)) {
			throw RelationLoaderException::relationKeyCountMismatch($relation);
		}

		foreach ($sourceKeys as $index => $sourceKey) {
			$join->on(
				x()->eq($source->field($sourceKey), $join->field($targetKeys[$index])),
			);
		}
	}

	/**
	 * @param non-empty-list<string> $sourceKeys
	 * @param non-empty-list<string> $targetKeys
	 */
	protected function assertMatchingKeys(RelationRef $relation, array $sourceKeys, array $targetKeys): void
	{
		if (count($sourceKeys) !== count($targetKeys)) {
			throw RelationLoaderException::relationKeyCountMismatch($relation);
		}
	}

	/**
	 * @return non-empty-list<string>
	 */
	protected function relationKeys(RelationRef $relation, string $side): array
	{
		try {
			$keys = $side === 'inner'
				? $relation->getRelation()->innerKeys()
				: $relation->getRelation()->outerKeys();
		} catch (LogicException) {
			throw RelationLoaderException::relationKeysIncomplete($relation);
		}

		if ($keys === []) {
			throw RelationLoaderException::relationKeysIncomplete($relation);
		}

		return $keys;
	}
}
