<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation;

use InvalidArgumentException;
use ON\Data\Definition\Relation\RelationKeyPairing;
use ON\Data\Query\Join;
use ON\Data\Query\QuerySourceInterface;
use ON\Data\Query\SelectQuery;
use function ON\Data\Query\x;

final class RelationKeyQuery
{
	public static function addJoinConditions(
		RelationKeyPairing $pairing,
		Join $join,
		QuerySourceInterface $leftSource,
	): void {
		foreach ($pairing->getPairs() as $pair) {
			$join->on(
				x()->eq($leftSource->field($pair['left']), $join->field($pair['right'])),
			);
		}
	}

	/**
	 * Correlate a right/child query source to a left/parent source for every key pair.
	 *
	 * Each pair becomes: right.field(rightKey) = left.field(leftKey).
	 */
	public static function correlateRightToLeft(
		RelationKeyPairing $pairing,
		SelectQuery $query,
		QuerySourceInterface $rightSource,
		QuerySourceInterface $leftSource,
	): void {
		foreach ($pairing->getPairs() as $pair) {
			$query->where(
				x()->eq(
					$rightSource->field($pair['right']),
					$leftSource->field($pair['left']),
				),
			);
		}
	}

	/**
	 * @param list<array<string, mixed>> $references
	 */
	public static function filterRightByLeftReferences(
		RelationKeyPairing $pairing,
		SelectQuery $query,
		QuerySourceInterface $rightSource,
		array $references,
	): void {
		if ($references === []) {
			return;
		}

		if (! $pairing->isComposite()) {
			$rightFields = $pairing->getRightFields();

			$query->where(
				x()->in(
					$rightSource->field($rightFields[0]),
					array_map(
						fn (array $values): mixed => self::referenceValue($pairing, $values, 0),
						$references,
					),
				),
			);

			return;
		}

		$predicates = [];

		foreach ($references as $values) {
			$comparisons = [];

			foreach ($pairing->getPairs() as $index => $pair) {
				$comparisons[] = x()->eq(
					$rightSource->field($pair['right']),
					self::referenceValue($pairing, $values, $index),
				);
			}

			$predicates[] = x()->and(...$comparisons);
		}

		$query->where(x()->or(...$predicates));
	}

	/**
	 * @param array<string, mixed> $values
	 */
	private static function referenceValue(RelationKeyPairing $pairing, array $values, int $index): mixed
	{
		$leftFields = $pairing->getLeftFields();
		$leftField = $leftFields[$index];

		if (array_key_exists($leftField, $values)) {
			return $values[$leftField];
		}

		$orderedValues = array_values($values);

		if (array_key_exists($index, $orderedValues)) {
			return $orderedValues[$index];
		}

		throw new InvalidArgumentException(sprintf(
			'Reference row is missing left field "%s".',
			$leftField,
		));
	}
}
