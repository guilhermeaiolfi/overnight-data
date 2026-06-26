<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * @implements IteratorAggregate<int, RelationRef>
 */
final class RelationSelectionTree implements IteratorAggregate, Countable
{
	/**
	 * @var array<string, RelationRef>
	 */
	private array $relations = [];

	public function add(RelationRef $relation): void
	{
		$ancestors = [];

		for ($current = $relation; $current !== null; $current = $current->getParentRelation()) {
			$ancestors[] = $current;
		}

		foreach (array_reverse($ancestors) as $ancestor) {
			$key = $this->identityFor($ancestor);

			if (! isset($this->relations[$key])) {
				$this->relations[$key] = $ancestor;
			}
		}
	}

	/**
	 * @return list<RelationRef>
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
	 * @return Traversable<int, RelationRef>
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
