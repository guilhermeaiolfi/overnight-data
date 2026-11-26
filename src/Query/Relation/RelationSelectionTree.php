<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use ON\Data\Query\Exception\RelationSelectionException;
use ON\Data\Query\SelectQuery;
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

	/** @var array<string, array<string, RelationSelection>> */
	private array $children = [];

	private ?SelectQuery $owner = null;

	public function add(RelationRef $relation): void
	{
		if (! $relation->isVisible()) {
			throw RelationSelectionException::hiddenTerminalRelation($relation->getPath());
		}

		if ($this->owner !== null && $this->owner !== $relation->getQuery()) {
			throw RelationSelectionException::foreignQueryRelation($relation, $this->owner);
		}
		$this->owner = $relation->getQuery();

		$segments = [];

		for ($current = $relation; $current !== null; $current = $current->getParentRelation()) {
			$segments[] = $current;
		}

		$segments = array_reverse($segments);
		foreach ($segments as $segment) {
			$key = RelationSelection::pathKey($segment->getPath());
			$previous = $this->relations[$key] ?? null;
			$loaded = $segment === $relation || $segment->isLoaded() || $previous?->isLoaded();

			$selection = new RelationSelection(
				$segment,
				$loaded,
				$loaded || $segment->isVisible() || $previous?->isVisible(),
				$segment->hasDefaultSelection() ? null : clone $segment->getSelections(),
				$segment->getConditions(),
				$segment->getSorts(),
				$segment->getLimit(),
				$segment->hasOffset() ? $segment->getOffset() : null,
				$segment->getStrategy(),
			);
			$this->relations[$key] = $selection;
			$this->children[$selection->getParentPathKey() ?? ''][$selection->getName()] = $selection;
		}
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
		return $this->children[$parent === null ? '' : RelationSelection::pathKey($parent->getPath())] ?? [];
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
}
