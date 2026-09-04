<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation\Loader;

use ON\Data\Query\DerivedSelectQuery;
use ON\Data\Query\Expression\SourceFieldExpression;
use ON\Data\Query\Expression\StarExpression;
use function ON\Data\Query\query;
use ON\Data\Query\Selection\SelectionItem;
use ON\Data\Query\Selection\SelectionTag;
use ON\Data\Query\SelectQuery;
use ON\Data\Query\Sort\Sort;
use ON\Data\Query\SourceMap;
use function ON\Data\Query\x;

/**
 * Shared ROW_NUMBER wrap for per-parent relation ranking.
 *
 * Loaders supply already-resolved partition fields and order. They keep
 * rank filters and outer ordering.
 */
final class PartitionedRelationQuery
{
	public const RANK_ALIAS = '__ondata_rank';

	private function __construct(
		private readonly SelectQuery $query,
		private readonly DerivedSelectQuery $ranked,
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
	): self {
		$inner = $childQuery->copy();
		$partitionBy = [];

		foreach ($partitionFieldNames as $fieldName) {
			$partitionBy[] = $inner->field($fieldName);
		}

		// Keeping the default star alongside projected columns makes MySQL reject
		// the derived table (Duplicate column name).
		$inner->getSelections()->removeByTag(SelectionTag::DEFAULT);

		foreach ($partitionFieldNames as $fieldName) {
			$inner->getSelections()->ensureInternalField($inner->field($fieldName));
		}

		$inner->getSelections()->ensureInternalExpression(
			x()->fn()->rowNumber()->over(
				partitionBy: $partitionBy,
				orderBy: array_map(
					static fn (Sort $sort): Sort => $sort->rebind(SourceMap::of($childQuery, $inner)),
					$orderBy,
				),
			),
			self::RANK_ALIAS,
		);

		$ranked = $inner->as($derivedAlias);
		$outer = query($ranked);

		$outer->getSelections()->removeByTag(SelectionTag::DEFAULT);
		$outer->getSelections()->merge(
			$inner->getSelections()
				->filterByTag(SelectionTag::COLUMN)
				->filter(static fn (SelectionItem $selection): bool => ! $selection->getExpression() instanceof StarExpression)
				->projectDerivedTo($ranked, $outer),
		);

		return new self($outer, $ranked);
	}

	public function getQuery(): SelectQuery
	{
		return $this->query;
	}

	public function rank(): SourceFieldExpression
	{
		return $this->rankedField(self::RANK_ALIAS);
	}

	public function partitionField(string $fieldName): SourceFieldExpression
	{
		return $this->rankedField($fieldName);
	}

	private function rankedField(string $fieldName): SourceFieldExpression
	{
		/** @var SourceFieldExpression $field */
		$field = $this->ranked->field($fieldName);

		return $field;
	}
}
