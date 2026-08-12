<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation;

use LogicException;
use ON\Data\Query\Expression\FieldRef;
use ON\Data\Query\QuerySourceInterface;
use ON\Data\Query\Selection\SelectionTag;
use ON\Data\Query\SelectQuery;

/**
 * SQL aliases for load columns: {@see aliasForPath} (empty path → output name).
 */
final class LoadAliasAllocator
{
	/**
	 * Column SQL alias: empty path uses the output name; otherwise path.name.
	 *
	 * @param list<string> $path
	 */
	public function aliasForPath(array $path, string $outputName): string
	{
		return $path === [] ? $outputName : implode('.', [...$path, $outputName]);
	}

	/**
	 * Return $preferred when it is free or already this source field; otherwise
	 * a collision-safe suffix.
	 */
	public function ensureSqlAlias(
		SelectQuery $query,
		QuerySourceInterface $source,
		string $fieldName,
		string $preferred,
	): string {
		if (
			! $this->isAliasTaken($query, $preferred)
			|| $this->isAliasForSameField($query, $preferred, $source, $fieldName)
		) {
			return $preferred;
		}

		return $this->nextFreeAlias($query, $source, $fieldName, $preferred);
	}

	public function emitColumn(
		SelectQuery $query,
		QuerySourceInterface $source,
		string $fieldName,
		string $alias,
		bool $sqlOnly,
	): void {
		if ($this->isAliasForSameField($query, $alias, $source, $fieldName)) {
			return;
		}

		if ($this->isAliasTaken($query, $alias)) {
			throw new LogicException(
				'Load alias "' . $alias . '" is taken by a different selection; ensureSqlAlias should have allocated a free key.',
			);
		}

		$expression = $source->field($fieldName)->as($alias);

		if ($sqlOnly) {
			$query->getSelections()->add(
				$expression,
				[SelectionTag::SQL_ONLY, SelectionTag::COLUMN],
			);

			return;
		}

		$query->select($expression);
	}

	/**
	 * True when $alias already projects this source field, so the column can be reused.
	 */
	public function isAliasForSameField(
		SelectQuery $query,
		string $alias,
		QuerySourceInterface $source,
		string $fieldName,
	): bool {
		$existing = $query->getSelections()->findBySelectionKey($alias);

		if ($existing === null) {
			return false;
		}

		$fieldRef = $existing->getFieldRef();

		if (! $fieldRef instanceof FieldRef || $fieldRef->getField()->getName() !== $fieldName) {
			return false;
		}

		return $this->isSameSource($fieldRef->getSource(), $source);
	}

	private function isAliasTaken(SelectQuery $query, string $alias): bool
	{
		$selections = $query->getSelections();

		return $selections->hasSelectionKey($alias) || $selections->hasNamedExpression($alias);
	}

	private function isSameSource(QuerySourceInterface $left, QuerySourceInterface $right): bool
	{
		if ($left === $right) {
			return true;
		}

		if ($left instanceof RelationRef && $left->hasJoinedSource() && $left->getJoinedSource() === $right) {
			return true;
		}

		if ($right instanceof RelationRef && $right->hasJoinedSource() && $right->getJoinedSource() === $left) {
			return true;
		}

		if ($left instanceof RelationRef && $right instanceof RelationRef) {
			return $left->getQuery() === $right->getQuery()
				&& $left->getPath() === $right->getPath();
		}

		return false;
	}

	private function nextFreeAlias(
		SelectQuery $query,
		QuerySourceInterface $source,
		string $fieldName,
		string $preferred,
	): string {
		$candidate = $preferred;
		$suffix = 2;

		while ($this->isAliasTaken($query, $candidate)) {
			if ($this->isAliasForSameField($query, $candidate, $source, $fieldName)) {
				return $candidate;
			}

			$candidate = $preferred . '_' . $suffix;
			++$suffix;
		}

		return $candidate;
	}
}
