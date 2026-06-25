<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation\Loader;

use ON\Data\Definition\Relation\M2MRelation;
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

		$throughDefinition = $definition->getThrough();

		if ($throughDefinition->getWhere() !== []) {
			throw RelationLoaderException::throughWhereNotSupported($relation);
		}

		$source = $relation->getParentSource();
		$type = $definition->isNullable() ? JoinType::LEFT : JoinType::INNER;
		$query = $relation->getQuery();
		$through = $query->join(
			$throughDefinition->getCollection(),
			$type,
			implode('.', $relation->getPath()) . '@through',
			$source,
		);

		$this->addM2MConditions(
			$through,
			$source,
			$definition->innerKeys(),
			$throughDefinition->throughInnerKeys(),
			$relation,
			'has mismatched through key counts.',
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
			$throughDefinition->throughOuterKeys(),
			$definition->outerKeys(),
			$relation,
			'has mismatched through key counts.',
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
		RelationRef $relation,
		string $mismatchReason,
	): void {
		if ($sourceKeys === [] || $targetKeys === []) {
			throw RelationLoaderException::relationKeysIncomplete($relation);
		}

		if (count($sourceKeys) !== count($targetKeys)) {
			throw RelationLoaderException::malformedThrough($relation, $mismatchReason);
		}

		foreach ($sourceKeys as $index => $sourceKey) {
			$join->on(
				x()->eq($source->field($sourceKey), $join->field($targetKeys[$index])),
			);
		}
	}
}
