<?php

declare(strict_types=1);

namespace ON\Data\Definition\Relation;

use ArrayIterator;
use IteratorAggregate;
use ON\Data\Definition\Exception\RelationException;
use Traversable;

/**
 * @implements IteratorAggregate<string, RelationInterface>
 */
final class RelationMap implements IteratorAggregate
{
	/** @var array<string, RelationInterface> */
	private array $relations = [];

	public function __clone()
	{
		foreach ($this->relations as $name => $relation) {
			$this->relations[$name] = clone $relation;
		}
	}

	public function has(string $name): bool
	{
		return isset($this->relations[$name]);
	}

	public function get(string $name): RelationInterface
	{
		if (! $this->has($name)) {
			throw new RelationException("Undefined relation `{$name}`");
		}

		return $this->relations[$name];
	}

	public function set(string $name, RelationInterface $relation): self
	{
		if ($this->has($name)) {
			throw new RelationException("Relation `{$name}` already exists");
		}

		$this->relations[$name] = $relation;

		return $this;
	}

	public function remove(string $name): self
	{
		unset($this->relations[$name]);

		return $this;
	}

	/**
	 * @return Traversable<string, RelationInterface>
	 */
	public function getIterator(): Traversable
	{
		return new ArrayIterator($this->relations);
	}
}
