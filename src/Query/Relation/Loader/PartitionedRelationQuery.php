<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation\Loader;

use ON\Data\Query\DerivedOutputColumns;
use ON\Data\Query\DerivedSelectQuery;
use ON\Data\Query\Expression\FieldRef;
use ON\Data\Query\Expression\SourceFieldExpression;
use function ON\Data\Query\query;
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
	): self {
		$inner = $childQuery->copy();
		$inner->getSelections()->removeByTag(SelectionTag::DEFAULT);
		$carryAliases = DerivedOutputColumns::names($inner);

		$partitionBy = [];
		$partitionAliases = [];

		foreach ($partitionFieldNames as $fieldName) {
			$field = $inner->field($fieldName);
			$partitionBy[] = $field;
			$existingAlias = self::findOwnFieldAlias($inner, $fieldName);

			if ($existingAlias !== null) {
				$partitionAliases[$fieldName] = $existingAlias;

				continue;
			}

			$partAlias = '__ondata_part_' . $fieldName;
			$inner->getSelections()->addExplicit([$field->as($partAlias)]);
			$partitionAliases[$fieldName] = $partAlias;
		}

		$inner->getSelections()->addExplicit([
			x()->fn()->rowNumber()->over(
				partitionBy: $partitionBy,
				orderBy: array_map(
					static fn (Sort $sort): Sort => $sort->rebind(SourceMap::of($childQuery, $inner)),
					$orderBy,
				),
			)->as(self::RANK_ALIAS),
		]);

		$ranked = $inner->as($derivedAlias);
		$outer = query($ranked);
		$outer->getSelections()->clear();

		if ($carryAliases !== []) {
			$outer->getSelections()->addExplicit(array_map(
				static fn (string $alias) => $outer->field($alias)->as($alias),
				$carryAliases,
			));
		}

		return new self($outer, $ranked, $partitionAliases);
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
		return $this->rankedField($this->partitionAliases[$fieldName] ?? $fieldName);
	}

	private function rankedField(string $fieldName): SourceFieldExpression
	{
		/** @var SourceFieldExpression $field */
		$field = $this->ranked->field($fieldName);

		return $field;
	}

	private static function findOwnFieldAlias(SelectQuery $query, string $fieldName): ?string
	{
		foreach ($query->getSelections()->getAll() as $selection) {
			$fieldRef = $selection->getFieldRef();

			if ($fieldRef instanceof FieldRef && $fieldRef->getSource() === $query && $fieldRef->getField()->getName() === $fieldName) {
				return $selection->getSelectionKey();
			}
		}

		return null;
	}
}
