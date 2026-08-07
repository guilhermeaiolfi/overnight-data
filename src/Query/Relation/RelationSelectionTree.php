<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use ON\Data\Query\Exception\RelationSelectionException;
use ON\Data\Query\Selection\SelectionList;
use Traversable;

/**
 * @implements IteratorAggregate<int, RelationSelection>
 */
final class RelationSelectionTree implements IteratorAggregate, Countable
{
	/**
	 * @var array<string, RelationSelection>
	 */
	private array $relations = [];

	public function add(RelationRef $relation): void
	{
		if (! $relation->isVisible()) {
			throw RelationSelectionException::hiddenTerminalRelation($relation->getPath());
		}

		$segments = [];

		for ($current = $relation; $current !== null; $current = $current->getParentRelation()) {
			$segments[] = $current;
		}

		$segments = array_reverse($segments);
		$terminalIndex = count($segments) - 1;

		foreach ($segments as $index => $segment) {
			$key = $this->identityFor($segment);
			$selections = $segment->hasDefaultSelection()
				? null
				: $this->copySelections($segment);
			$selection = new RelationSelection(
				$segment,
				$index === $terminalIndex ? true : $segment->isLoaded(),
				$index === $terminalIndex ? true : $segment->isVisible(),
				$selections,
				$segment->getConditions(),
				$segment->getSorts(),
				$segment->getLimit(),
				$segment->hasOffset() ? $segment->getOffset() : null,
				$segment->getStrategy(),
			);

			$this->relations[$key] = isset($this->relations[$key])
				? $this->relations[$key]->merge($selection)
				: $selection;
		}
	}

	private function copySelections(RelationRef $segment): SelectionList
	{
		$copy = new SelectionList();
		$copy->merge($segment->getSelections());

		return $copy;
	}

	/**
	 * @return list<RelationSelection>
	 */
	public function getAll(): array
	{
		return array_values($this->relations);
	}

	/**
	 * Direct child relation selections under `$parent` (root children when null).
	 *
	 * @return array<string, RelationSelection> keyed by relation name
	 */
	public function childrenOf(?RelationSelection $parent = null): array
	{
		$parentPath = $parent?->getPath();
		$children = [];

		foreach ($this->relations as $selection) {
			$path = $selection->getPath();

			if ($parentPath === null) {
				if (count($path) === 1) {
					$children[$selection->getName()] = $selection;
				}

				continue;
			}

			if (
				count($path) === count($parentPath) + 1
				&& array_slice($path, 0, -1) === $parentPath
			) {
				$children[$selection->getName()] = $selection;
			}
		}

		return $children;
	}

	public function isEmpty(): bool
	{
		return $this->relations === [];
	}

	public function count(): int
	{
		return count($this->relations);
	}

	/**
	 * @return Traversable<int, RelationSelection>
	 */
	public function getIterator(): Traversable
	{
		return new ArrayIterator($this->getAll());
	}

	private function identityFor(RelationRef $relation): string
	{
		return json_encode($relation->getPath(), JSON_THROW_ON_ERROR);
	}
}
