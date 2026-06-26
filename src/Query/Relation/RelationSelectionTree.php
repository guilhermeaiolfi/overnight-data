<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use ON\Data\Query\Exception\RelationSelectionException;
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
			$selection = new RelationSelection(
				$segment,
				$index === $terminalIndex ? true : $segment->isLoaded(),
				$index === $terminalIndex ? true : $segment->isVisible(),
				$segment->getFields(),
			);

			$this->relations[$key] = isset($this->relations[$key])
				? $this->relations[$key]->merge($selection)
				: $selection;
		}
	}

	/**
	 * @return list<RelationSelection>
	 */
	public function getAll(): array
	{
		return array_values($this->relations);
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
