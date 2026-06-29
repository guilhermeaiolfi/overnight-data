<?php

declare(strict_types=1);

namespace ON\Data\Definition\Relation;

use ArrayIterator;
use IteratorAggregate;
use ON\Data\Definition\DefinitionInterface;
use ON\Data\Definition\Exception\DefinitionNameConflictException;
use ON\Data\Definition\Exception\InvalidDefinitionClassException;
use ON\Data\Definition\Exception\RelationException;
use ON\Data\Definition\Internal\DefinitionFactory;
use Traversable;

/**
 * @implements IteratorAggregate<string, RelationInterface>
 */
final class RelationMap implements IteratorAggregate
{
	/** @var array<string, mixed> */
	private array $items;

	/** @var array<string, RelationInterface> */
	private array $relations = [];

	/**
	 * @param array<string, mixed> $items
	 */
	public function __construct(
		private DefinitionInterface $parent,
		array &$items,
	) {
		$this->items = &$items;
	}

	public function has(string $name): bool
	{
		return array_key_exists($name, $this->items);
	}

	public function get(string $name): RelationInterface
	{
		if (! $this->has($name) || ! is_array($this->items[$name])) {
			throw new RelationException("Undefined relation `{$name}`");
		}

		if (! isset($this->relations[$name])) {
			$items = &$this->items[$name];
			$this->relations[$name] = DefinitionFactory::restoreRelation($this->parent, $name, $items);
		}

		return $this->relations[$name];
	}

	public function createOrReturn(string $name, string $class, array $values = []): RelationInterface
	{
		if ($this->parent->hasField($name)) {
			throw new DefinitionNameConflictException(
				sprintf(
					"Definition '%s' member name '%s' is already used by a field. Field and relation member names must be unique within a collection.",
					$this->parent->getName(),
					$name
				)
			);
		}

		if ($this->has($name)) {
			$relation = $this->get($name);
			if ($relation::class !== $class) {
				throw new InvalidDefinitionClassException(
					sprintf("Cannot redefine relation '%s' with class '%s'; stored class is '%s'.", $name, $class, $relation::class)
				);
			}

			return $relation;
		}

		$this->items[$name] = [];
		$items = &$this->items[$name];
		$this->relations[$name] = DefinitionFactory::createRelation($this->parent, $name, $items, $class, $values);

		return $this->relations[$name];
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
