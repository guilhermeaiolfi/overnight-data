<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation\Loader;

use ON\Data\Query\DerivedOutputColumns;
use ON\Data\Query\DerivedSelectQuery;
use ON\Data\Query\Expression\FieldRef;
use ON\Data\Query\Expression\SourceFieldExpression;
use ON\Data\Query\Expression\ValueExpressionInterface;
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
	private const MEMBERSHIP_RANK_ALIAS = '__ondata_membership_rank';

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

		$orderBy = array_map(static fn (Sort $sort): Sort => $sort->rebind($sources), $orderBy);
		if ($through !== null) {
			// Take the first sorted row of each membership before numbering the page.
			[$inner, $partitionBy, $orderBy] = self::distinctMemberships($inner, $partitionBy, $partitionAliases, $orderBy);
		}

		$inner->getSelections()->addExplicit([
			x()->fn()->rowNumber()->over(partitionBy: $partitionBy, orderBy: $orderBy)->as(self::RANK_ALIAS),
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

	/**
	 * @param list<ValueExpressionInterface> $partitionBy
	 * @param array<string, string> $partitionAliases
	 * @param list<Sort> $orderBy
	 * @return array{SelectQuery, list<ValueExpressionInterface>, list<Sort>}
	 */
	private static function distinctMemberships(
		SelectQuery $inner,
		array $partitionBy,
		array $partitionAliases,
		array $orderBy,
	): array {
		foreach ($inner->getCollection()->getPrimaryKey() as $field) {
			$partitionBy[] = $inner->field($field);
		}
		$inner->getSelections()->addExplicit([
			x()->fn()->rowNumber()->over(partitionBy: $partitionBy, orderBy: $orderBy)->as(self::MEMBERSHIP_RANK_ALIAS),
		]);

		$sortDirections = [];
		foreach ($orderBy as $index => $sort) {
			$alias = '__ondata_sort_' . $index;
			$inner->getSelections()->addExplicit([$sort->getExpression()->as($alias)]);
			$sortDirections[$alias] = $sort->getDirection();
		}
		$memberships = $inner->as('__ondata_memberships');
		$distinct = query($memberships)->where($memberships->field(self::MEMBERSHIP_RANK_ALIAS)->eq(1));
		$partitionBy = array_map($memberships->field(...), array_values($partitionAliases));
		$sorts = [];
		foreach ($sortDirections as $alias => $direction) {
			$sorts[] = new Sort($memberships->field($alias), $direction);
		}

		return [$distinct, $partitionBy, $sorts];
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
