<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation;

use ON\Data\Query\Condition\ConditionInterface;
use ON\Data\Query\Selection\SelectionList;
use ON\Data\Query\Sort\Sort;

final class RelationSelection
{
	private readonly SelectionList $selections;

	private readonly bool $defaultSelection;

	public function __construct(
		private readonly RelationRef $relationRef,
		private readonly bool $load,
		private readonly bool $visible,
		?SelectionList $selections,
		private readonly array $conditions = [],
		private readonly array $sorts = [],
		private readonly ?int $limit = null,
		private readonly ?int $offset = null,
		private readonly ?LoadStrategy $strategy = null,
	) {
		$this->defaultSelection = $selections === null;
		$this->selections = $selections ?? new SelectionList();
	}

	public function getRelationRef(): RelationRef
	{
		return $this->relationRef;
	}

	public function getName(): string
	{
		return $this->relationRef->getName();
	}

	/**
	 * @return list<string>
	 */
	public function getPath(): array
	{
		return $this->relationRef->getPath();
	}

	/**
	 * Dot-joined relation path key (`posts.author`), matching schema sourcePath keys.
	 *
	 * @param list<string> $path
	 */
	public static function pathKey(array $path): string
	{
		return implode('.', $path);
	}

	public function getParentPathKey(): ?string
	{
		$path = $this->getPath();

		if (count($path) <= 1) {
			return null;
		}

		array_pop($path);

		return self::pathKey($path);
	}

	public function isLoaded(): bool
	{
		return $this->load;
	}

	public function isVisible(): bool
	{
		return $this->visible;
	}

	public function hasDefaultSelection(): bool
	{
		return $this->defaultSelection;
	}

	public function getSelections(): SelectionList
	{
		return $this->selections;
	}

	/**
	 * @return list<ConditionInterface>
	 */
	public function getConditions(): array
	{
		return $this->conditions;
	}

	/**
	 * @return list<Sort>
	 */
	public function getSorts(): array
	{
		return $this->sorts;
	}

	public function getStrategy(): ?LoadStrategy
	{
		return $this->strategy;
	}

	public function getLimit(): ?int
	{
		return $this->limit;
	}

	public function getOffset(): int
	{
		return $this->offset ?? 0;
	}

	public function hasOffset(): bool
	{
		return $this->offset !== null;
	}

	public function merge(self $incoming): self
	{
		$sameRelationRef = $this->relationRef === $incoming->relationRef;
		$load = $this->load || $incoming->load;
		$visible = $this->visible || $incoming->visible || $load;
		$defaultSelection = $this->mergeDefaultSelection($incoming);
		$selections = $defaultSelection ? null : $this->mergeSelections($incoming);
		$conditions = $sameRelationRef ? $incoming->conditions : [...$this->conditions, ...$incoming->conditions];
		$sorts = $sameRelationRef ? $incoming->sorts : [...$this->sorts, ...$incoming->sorts];
		$limit = $this->mergeLimit($incoming, $sameRelationRef);
		[$offset, $hasOffset] = $this->mergeOffset($incoming, $sameRelationRef);
		$strategy = $this->mergeStrategy($incoming);

		return new self(
			$this->relationRef,
			$load,
			$visible,
			$selections,
			$conditions,
			$sorts,
			$limit,
			$hasOffset ? $offset : null,
			$strategy,
		);
	}

	private function mergeDefaultSelection(self $incoming): bool
	{
		if ($this->relationRef === $incoming->relationRef) {
			return $incoming->defaultSelection;
		}

		return $this->defaultSelection || $incoming->defaultSelection;
	}

	private function mergeSelections(self $incoming): SelectionList
	{
		if ($this->relationRef === $incoming->relationRef) {
			$copy = new SelectionList();
			$copy->merge($incoming->selections);

			return $copy;
		}

		$merged = new SelectionList();
		$merged->merge($this->selections);
		$merged->merge($incoming->selections);

		return $merged;
	}

	private function mergeStrategy(self $incoming): ?LoadStrategy
	{
		return $incoming->strategy ?? $this->strategy;
	}

	private function mergeLimit(self $incoming, bool $sameRelationRef): ?int
	{
		if ($sameRelationRef) {
			return $incoming->limit;
		}

		return $incoming->limit ?? $this->limit;
	}

	/**
	 * @return array{0: int, 1: bool}
	 */
	private function mergeOffset(self $incoming, bool $sameRelationRef): array
	{
		if ($sameRelationRef) {
			return [$incoming->getOffset(), $incoming->hasOffset()];
		}

		if ($incoming->hasOffset()) {
			return [$incoming->getOffset(), true];
		}

		return [$this->getOffset(), $this->hasOffset()];
	}
}
