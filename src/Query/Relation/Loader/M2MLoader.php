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
use ON\Data\Query\Relation\RelationRef;
use function ON\Data\Query\x;

final class M2MLoader extends AbstractLoader
{
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
