<?php

declare(strict_types=1);

namespace ON\Data\Query;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Query\Exception\DuplicateDerivedOutputColumnException;
use ON\Data\Query\Expression\AliasedExpression;
use ON\Data\Query\Expression\FieldRef;
use ON\Data\Query\Expression\SourceFieldExpression;
use ON\Data\Query\Expression\StarExpression;
use ON\Data\Query\Expression\ValueExpressionInterface;
use ON\Data\Query\Selection\SelectionItem;

/**
 * Canonical output-column names for a query used as a derived FROM product.
 *
 * Rules:
 * - explicit alias → alias
 * - FieldRef / SourceFieldExpression → field name
 * - collection star → visible field names
 * - derived star → inner derived output names
 *
 * Duplicate canonical names are rejected; callers must alias colliding selections.
 */
final class DerivedOutputColumns
{
	/**
	 * @return list<string>
	 */
	public static function names(SelectQuery $query): array
	{
		$names = self::collectNames($query);
		self::assertUnique($query, $names);

		return $names;
	}

	public static function assertUniqueNames(SelectQuery $query): void
	{
		self::assertUnique($query, self::collectNames($query));
	}

	public static function exposes(SelectQuery $query, string $name): bool
	{
		return in_array($name, self::names($query), true);
	}

	/**
	 * @return list<string>
	 */
	public static function namesForSelection(SelectionItem $selection): array
	{
		$expression = $selection->getExpression();

		if ($expression instanceof AliasedExpression) {
			return [$expression->getAlias()];
		}

		if ($expression instanceof StarExpression) {
			return self::namesForStar($expression);
		}

		$name = self::expressionOutputName($expression);

		return $name === null ? [] : [$name];
	}

	/**
	 * Single output column name for a non-star expression (SQL AS / field() key).
	 */
	public static function expressionOutputName(ValueExpressionInterface $expression): ?string
	{
		if ($expression instanceof AliasedExpression) {
			return $expression->getAlias();
		}

		if ($expression instanceof FieldRef || $expression instanceof SourceFieldExpression) {
			return $expression->getName();
		}

		return null;
	}

	/**
	 * @return list<string>
	 */
	public static function namesForStar(StarExpression $star): array
	{
		$source = $star->getSource();

		if ($source instanceof DerivedSelectQuery) {
			return self::names($source->getInnerQuery());
		}

		if ($source instanceof SelectQuery) {
			if ($source->isDerivedSource()) {
				$from = $source->getFrom();

				if ($from instanceof DerivedSelectQuery) {
					return self::names($from->getInnerQuery());
				}

				return [];
			}

			$from = $source->getFrom();

			if ($from instanceof CollectionInterface) {
				$names = [];

				foreach ($from->getVisibleFields() as $fieldName) {
					$names[] = $from->getField($fieldName)->getName();
				}

				return $names;
			}

			return [];
		}

		if ($source instanceof Join) {
			$names = [];

			foreach ($source->getCollection()->getVisibleFields() as $fieldName) {
				$names[] = $source->getCollection()->getField($fieldName)->getName();
			}

			return $names;
		}

		return [];
	}

	/**
	 * @return list<string>
	 */
	private static function collectNames(SelectQuery $query): array
	{
		$names = [];

		foreach ($query->getSelections()->getAll() as $selection) {
			foreach (self::namesForSelection($selection) as $name) {
				$names[] = $name;
			}
		}

		return $names;
	}

	/**
	 * @param list<string> $names
	 */
	private static function assertUnique(SelectQuery $query, array $names): void
	{
		$seen = [];

		foreach ($names as $name) {
			if (isset($seen[$name])) {
				throw DuplicateDerivedOutputColumnException::forName($query, $name);
			}

			$seen[$name] = true;
		}
	}
}
