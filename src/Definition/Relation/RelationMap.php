<?php

declare(strict_types=1);

namespace ON\Data\Definition\Relation;

use ArrayIterator;
use IteratorAggregate;
use ON\Data\Definition\DefinitionInterface;
use ON\Data\Definition\Exception\RelationException;
use ON\Data\Definition\Internal\DefinitionFactory;
use Traversable;

/**
 * @implements IteratorAggregate<string, RelationInterface>
 */
final class RelationMap implements IteratorAggregate
{
	/** @var array<string, mixed> */
	private array $items = [];

	/** @var array<string, RelationInterface> */
	private array $relations = [];

	public function __construct(
		private ?DefinitionInterface $parent = null,
		?array &$items = null,
	) {
		if ($items !== null) {
			$this->items = &$items;
		}
	}

	public function __clone()
	{
		$items = $this->items;
		$this->items = $items;
		foreach ($this->relations as $name => $relation) {
			$this->relations[$name] = clone $relation;
			$this->items[$name] = $this->relations[$name]->all();
		}
	}

	public function has(string $name): bool
	{
		return array_key_exists($name, $this->items);
	}

	public function get(string $name): RelationInterface
	{
		if (! $this->has($name)) {
			throw new RelationException("Undefined relation `{$name}`");
		}

		if (isset($this->relations[$name])) {
			return $this->relations[$name];
		}

		if ($this->parent === null || ! is_array($this->items[$name])) {
			throw new RelationException("Undefined relation `{$name}`");
		}

		$items = &$this->items[$name];
		$this->relations[$name] = DefinitionFactory::relation($this->parent, $items);

		return $this->relations[$name];
	}

	public function set(string $name, RelationInterface $relation): self
	{
		if ($this->has($name)) {
			throw new RelationException("Relation `{$name}` already exists");
		}

		$this->items[$name] = $relation instanceof AbstractRelation ? $relation->all() : [];
		unset($this->relations[$name]);
		if ($this->parent !== null && is_array($this->items[$name])) {
			$items = &$this->items[$name];
			$this->relations[$name] = DefinitionFactory::relation($this->parent, $items);
		} else {
			$this->relations[$name] = $relation;
		}

		return $this;
	}

	public function remove(string $name): self
	{
		unset($this->items[$name], $this->relations[$name]);

		return $this;
	}

	public function replace(string $name, RelationInterface $relation): self
	{
		$this->items[$name] = $relation instanceof AbstractRelation ? $relation->all() : [];
		unset($this->relations[$name]);
		if ($this->parent !== null && is_array($this->items[$name])) {
			$items = &$this->items[$name];
			$this->relations[$name] = DefinitionFactory::relation($this->parent, $items);
		}

		return $this;
	}

	/**
	 * @return Traversable<string, RelationInterface>
	 */
	public function getIterator(): Traversable
	{
		$relations = [];
		foreach (array_keys($this->items) as $name) {
			$relations[$name] = $this->get((string) $name);
		}

		return new ArrayIterator($relations);
	}
}
