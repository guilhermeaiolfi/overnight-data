<?php

declare(strict_types=1);

namespace Tests\ON\Data\Support;

use ON\Data\Query\Expression\AliasedExpression;
use ON\Data\Query\Expression\FieldRef;
use ON\Data\Query\Expression\LiteralExpression;
use ON\Data\Query\Expression\StarExpression;
use ON\Data\Query\Join;
use ON\Data\Query\Relation\ColumnIndex;
use ON\Data\Query\Relation\RelationRef;
use ON\Data\Query\SelectQuery;

/**
 * Projects logical test fixture rows onto the SQL aliases (`c0`, `c1`, ...)
 * emitted onto a {@see SelectQuery} by {@see ColumnIndex}.
 */
final class StubQueryRowProjector
{
	/**
	 * @param array<string, mixed> $logicalRow Keys may be own field names (`id`),
	 *                                         dotted relation/join paths (`author.name`),
	 *                                         or literal selection aliases.
	 * @return array<string, mixed>
	 */
	public static function project(SelectQuery $query, array $logicalRow): array
	{
		$projected = [];

		foreach ($query->getSelections()->getAll() as $selection) {
			$expression = $selection->getExpression();

			if ($expression instanceof StarExpression) {
				foreach ($logicalRow as $key => $value) {
					$projected[$key] = $value;
				}

				continue;
			}

			$alias = $selection->getSelectionKey();

			if (array_key_exists($alias, $logicalRow)) {
				$projected[$alias] = $logicalRow[$alias];

				continue;
			}

			$inner = $expression instanceof AliasedExpression
				? $expression->getExpression()
				: $expression;

			if ($inner instanceof FieldRef) {
				$source = $inner->getSource();
				$fieldName = $inner->getField()->getName();

				if ($source === $query) {
					if (array_key_exists($fieldName, $logicalRow)) {
						$projected[$alias] = $logicalRow[$fieldName];
					}

					continue;
				}

				$prefix = match (true) {
					$source instanceof Join => $source->getName(),
					$source instanceof RelationRef => implode('.', $source->getPath()),
					default => implode('.', $source->getPath()),
				};
				$qualified = $prefix !== '' ? $prefix . '.' . $fieldName : $fieldName;

				if (array_key_exists($qualified, $logicalRow)) {
					$projected[$alias] = $logicalRow[$qualified];
				}

				continue;
			}

			if ($inner instanceof LiteralExpression) {
				$projected[$alias] = $inner->getValue();
			}
		}

		return $projected;
	}

	/**
	 * @param list<array<string, mixed>> $logicalRows
	 * @return list<array<string, mixed>>
	 */
	public static function projectAll(SelectQuery $query, array $logicalRows): array
	{
		return array_map(
			static fn (array $row): array => self::project($query, $row),
			$logicalRows,
		);
	}
}
