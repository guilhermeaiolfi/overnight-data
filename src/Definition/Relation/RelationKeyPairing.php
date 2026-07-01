<?php

declare(strict_types=1);

namespace ON\Data\Definition\Relation;

use InvalidArgumentException;
use ON\Data\Query\Join;
use ON\Data\Query\QuerySourceInterface;
use ON\Data\Query\Relation\LoadBranch;
use ON\Data\Query\SelectQuery;
use function ON\Data\Query\x;

final class RelationKeyPairing
{
	private ?self $reversed = null;

	/**
	 * @var list<array{left: string, right: string}>
	 */
	private array $pairs;

	/**
	 * @param list<string> $leftFields
	 * @param list<string> $rightFields
	 */
	private function __construct(
		private readonly array $leftFields,
		private readonly array $rightFields,
	) {
		$this->pairs = array_map(
			static fn (string $left, string $right): array => ['left' => $left, 'right' => $right],
			$this->leftFields,
			$this->rightFields,
		);
	}

	/**
	 * @param list<string> $leftFields
	 * @param list<string> $rightFields
	 */
	public static function from(array $leftFields, array $rightFields): self
	{
		$leftFields = array_values($leftFields);
		$rightFields = array_values($rightFields);

		if ($leftFields === []) {
			throw new InvalidArgumentException('Relation key pairing requires at least one left field.');
		}

		if ($rightFields === []) {
			throw new InvalidArgumentException('Relation key pairing requires at least one right field.');
		}

		if (count($leftFields) !== count($rightFields)) {
			throw new InvalidArgumentException(sprintf(
				'Relation key pairing count mismatch: left has %d fields and right has %d.',
				count($leftFields),
				count($rightFields),
			));
		}

		return new self($leftFields, $rightFields);
	}

	/**
	 * @return list<string>
	 */
	public function getLeftFields(): array
	{
		return $this->leftFields;
	}

	/**
	 * @return list<string>
	 */
	public function getRightFields(): array
	{
		return $this->rightFields;
	}

	/**
	 * @return list<array{left: string, right: string}>
	 */
	public function getPairs(): array
	{
		return $this->pairs;
	}

	public function count(): int
	{
		return count($this->pairs);
	}

	public function isComposite(): bool
	{
		return $this->count() > 1;
	}

	public function reverse(): self
	{
		if ($this->reversed !== null) {
			return $this->reversed;
		}

		$reversed = new self($this->rightFields, $this->leftFields);
		$reversed->reversed = $this;

		return $this->reversed = $reversed;
	}

	/**
	 * @return list<string>
	 */
	public function requireLeft(LoadBranch $branch): array
	{
		return $branch->requireFields($this->leftFields);
	}

	/**
	 * @return list<string>
	 */
	public function requireRight(LoadBranch $branch): array
	{
		return $branch->requireFields($this->rightFields);
	}

	public function addJoinConditions(Join $join, QuerySourceInterface $leftSource): void
	{
		foreach ($this->pairs as $pair) {
			$join->on(
				x()->eq($leftSource->field($pair['left']), $join->field($pair['right'])),
			);
		}
	}

	/**
	 * @param list<array<string, mixed>> $references
	 */
	public function filterRightByLeftReferences(
		SelectQuery $query,
		QuerySourceInterface $rightSource,
		array $references,
	): void {
		if ($references === []) {
			return;
		}

		if (! $this->isComposite()) {
			$query->where(
				x()->in(
					$rightSource->field($this->rightFields[0]),
					array_map(
						fn (array $values): mixed => $this->referenceValue($values, 0),
						$references,
					),
				),
			);

			return;
		}

		$predicates = [];

		foreach ($references as $values) {
			$comparisons = [];

			foreach ($this->pairs as $index => $pair) {
				$comparisons[] = x()->eq($rightSource->field($pair['right']), $this->referenceValue($values, $index));
			}

			$predicates[] = x()->and(...$comparisons);
		}

		$query->where(x()->or(...$predicates));
	}

	/**
	 * @param array<string, mixed> $values
	 */
	private function referenceValue(array $values, int $index): mixed
	{
		$leftField = $this->leftFields[$index];

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
