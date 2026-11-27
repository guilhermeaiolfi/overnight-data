<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation\Loader;

use ON\Data\Query\DerivedOutputColumns;
use ON\Data\Query\DerivedSelectQuery;
use ON\Data\Query\Expression\FieldRef;
use ON\Data\Query\Expression\SourceFieldExpression;
use ON\Data\Query\Join;
use function ON\Data\Query\query;
use ON\Data\Query\QuerySourceInterface;
use ON\Data\Query\Selection\SelectionTag;
use ON\Data\Query\SelectQuery;
use ON\Data\Query\Sort\Sort;
use ON\Data\Query\SourceMap;
use function ON\Data\Query\x;

/**
 * Shared window query for per-parent relation ranking.
 */
final class PartitionedRelationQuery
{
	public const RANK_ALIAS = '__ondata_rank';

	/**
	 * @param array<string, string> $partitionAliases field name => derived column alias
	 */
	private function __construct(
		private readonly SelectQuery $query,
		private readonly DerivedSelectQuery $ranked,
		private readonly array $partitionAliases,
	) {
	}

	/**
	 * @param list<string> $partitionFieldNames
	 * @param list<Sort> $orderBy
	 */
	public static function wrap(
		SelectQuery $childQuery,
		array $partitionFieldNames,
		array $orderBy,
		string $derivedAlias,
		?Join $through = null,
	): self {
		$inner = $childQuery->copy();
		$inner->getSelections()->removeByTag(SelectionTag::DEFAULT);
		$carryAliases = DerivedOutputColumns::names($inner);
		$carryFields = [];
		foreach ($inner->getSelections()->getAll() as $selection) {
			$field = $selection->getFieldRef();
			if ($field !== null) {
				$carryFields[$selection->getSelectionKey()] = $field->getField();
			}
		}
		$sources = SourceMap::of($childQuery, $inner);
		$innerJoins = $inner->getJoins();
		foreach ($childQuery->getJoins() as $index => $join) {
			$sources = $sources->with($join, $innerJoins[$index]);
		}
		$partitionSource = $through === null ? $inner : $sources->remap($through);

		$partitionBy = [];
		$partitionAliases = [];

		foreach ($partitionFieldNames as $fieldName) {
			$field = $partitionSource->field($fieldName);
			$partitionBy[] = $field;
			$existingAlias = self::findFieldAlias($inner, $partitionSource, $fieldName);

			if ($existingAlias !== null) {
				$partitionAliases[$fieldName] = $existingAlias;

				continue;
			}

			$partAlias = '__ondata_part_' . $fieldName;
			$inner->getSelections()->addExplicit([$field->as($partAlias)]);
			$partitionAliases[$fieldName] = $partAlias;
		}

		// Duplicate junction rows attach the same target and must share one page position.
		$rank = $through === null ? x()->fn()->rowNumber() : x()->fn()->denseRank();
		$inner->getSelections()->addExplicit([
			$rank->over(
				partitionBy: $partitionBy,
				orderBy: array_map(
					static fn (Sort $sort): Sort => $sort->rebind($sources),
					$orderBy,
				),
			)->as(self::RANK_ALIAS),
		]);

		$ranked = $inner->as($derivedAlias);
		$outer = query($ranked);
		$outer->getSelections()->clear();

		if ($carryAliases !== []) {
			$outer->getSelections()->addExplicit(array_map(
				static fn (string $alias) => (new SourceFieldExpression($ranked, $alias, $carryFields[$alias] ?? null))->as($alias),
				$carryAliases,
			));
		}

		return new self($outer, $ranked, $partitionAliases);
	}

	public function getQuery(): SelectQuery
	{
		return $this->query;
	}

	public function page(int $offset, ?int $limit): SelectQuery
	{
		if ($offset > 0) {
			$this->query->where($this->rank()->gt($offset));
		}
		if ($limit !== null) {
			$this->query->where($this->rank()->lte($offset + $limit));
		}
		foreach (array_keys($this->partitionAliases) as $fieldName) {
			$this->query->orderBy($this->partitionField($fieldName)->asc());
		}

		return $this->query->orderBy($this->rank()->asc());
	}

	public function rank(): SourceFieldExpression
	{
		return $this->rankedField(self::RANK_ALIAS);
	}

	public function partitionField(string $fieldName): SourceFieldExpression
	{
		return $this->rankedField($this->partitionAliases[$fieldName] ?? $fieldName);
	}

	private function rankedField(string $fieldName): SourceFieldExpression
	{
		/** @var SourceFieldExpression $field */
		$field = $this->ranked->field($fieldName);

		return $field;
	}

	private static function findFieldAlias(SelectQuery $query, QuerySourceInterface $source, string $fieldName): ?string
	{
		foreach ($query->getSelections()->getAll() as $selection) {
			$fieldRef = $selection->getFieldRef();

			if ($fieldRef instanceof FieldRef && $fieldRef->getSource() === $source && $fieldRef->getField()->getName() === $fieldName) {
				return $selection->getSelectionKey();
			}
		}

		return null;
	}
}
