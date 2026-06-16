<?php

declare(strict_types=1);

namespace ON\Data\Support;

use ArrayAccess;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use ReturnTypeWillChange;
use Traversable;

/**
 * Minimal dot-path array helper extracted from Overnight's Config support.
 *
 * @phpstan-consistent-constructor
 * @implements ArrayAccess<array-key, mixed>
 * @implements IteratorAggregate<array-key, mixed>
 */
class Dot implements ArrayAccess, Countable, IteratorAggregate, JsonSerializable
{
	/**
	 * @var array<array-key, mixed>
	 */
	protected array $items = [];

	/**
	 * @var non-empty-string
	 */
	protected string $delimiter = '.';

	/**
	 * @param array<array-key, mixed>|object|null $items
	 * @param string $delimiter
	 */
	public function __construct(array|object|null $items = [], bool $parse = false, string $delimiter = '.')
	{
		$this->delimiter = $delimiter !== '' ? $delimiter : '.';
		$arrayItems = $this->getArrayItems($items);

		if ($parse) {
			$this->set($arrayItems);

			return;
		}

		$this->items = $arrayItems;
	}

	/**
	 * @param array<array-key, mixed>|object|null $items
	 * @param string $delimiter
	 */
	public static function create(array|object|null $items = [], bool $parse = false, string $delimiter = '.'): static
	{
		return new static($items, $parse, $delimiter);
	}

	/**
	 * @return array<array-key, mixed>
	 */
	public function all(): array
	{
		return $this->items;
	}

	public function get(string|int|null $key = null, mixed $default = null): mixed
	{
		if ($key === null) {
			return $this->items;
		}

		if ($this->exists($this->items, $key)) {
			return $this->items[$key];
		}

		if (! is_string($key) || ! str_contains($key, $this->delimiter)) {
			return $default;
		}

		$items = $this->items;

		foreach (explode($this->delimiter, $key) as $segment) {
			if (! is_array($items) || ! $this->exists($items, $segment)) {
				return $default;
			}

			$items = $items[$segment];
		}

		return $items;
	}

	/**
	 * @param array<array-key>|int|string $keys
	 */
	public function has(array|int|string $keys): bool
	{
		$keys = (array) $keys;

		if ($this->items === [] || $keys === []) {
			return false;
		}

		foreach ($keys as $key) {
			$items = $this->items;

			if ($this->exists($items, $key)) {
				continue;
			}

			if (! is_string($key)) {
				return false;
			}

			foreach (explode($this->delimiter, $key) as $segment) {
				if (! is_array($items) || ! $this->exists($items, $segment)) {
					return false;
				}

				$items = $items[$segment];
			}
		}

		return true;
	}

	/**
	 * @param array<array-key, mixed>|int|string $keys
	 */
	public function set(array|int|string $keys, mixed $value = null): static
	{
		if (is_array($keys)) {
			foreach ($keys as $key => $nestedValue) {
				$this->set($key, $nestedValue);
			}

			return $this;
		}

		if (is_int($keys)) {
			$this->items[$keys] = $value;

			return $this;
		}

		$items = &$this->items;

		foreach (explode($this->delimiter, $keys) as $segment) {
			if (! isset($items[$segment]) || ! is_array($items[$segment])) {
				$items[$segment] = [];
			}

			$items = &$items[$segment];
		}

		$items = $value;

		return $this;
	}

	/**
	 * @param array<array-key>|int|string $keys
	 */
	public function delete(array|int|string $keys): static
	{
		foreach ((array) $keys as $key) {
			if ($this->exists($this->items, $key)) {
				unset($this->items[$key]);

				continue;
			}

			if (! is_string($key)) {
				continue;
			}

			$items = &$this->items;
			$segments = explode($this->delimiter, $key);
			$lastSegment = array_pop($segments);

			foreach ($segments as $segment) {
				if (! isset($items[$segment]) || ! is_array($items[$segment])) {
					continue 2;
				}

				$items = &$items[$segment];
			}

			unset($items[$lastSegment]);
		}

		return $this;
	}

	/**
	 * @param array<array-key, mixed> $items
	 */
	public function setArray(array $items): static
	{
		$this->items = $items;

		return $this;
	}

	/**
	 * @param array<array-key, mixed> $items
	 */
	public function setReference(array &$items): static
	{
		$this->items = &$items;

		return $this;
	}

	/**
	 * @param array<array-key, mixed> $array
	 */
	protected function exists(array $array, int|string $key): bool
	{
		return array_key_exists($key, $array);
	}

	/**
	 * @param array<array-key, mixed>|object|null $items
	 * @return array<array-key, mixed>
	 */
	protected function getArrayItems(array|object|null $items): array
	{
		if ($items instanceof self) {
			return $items->all();
		}

		if (is_array($items)) {
			return $items;
		}

		return (array) $items;
	}

	public function offsetExists(mixed $offset): bool
	{
		return $this->has($offset);
	}

	#[ReturnTypeWillChange]
	public function offsetGet(mixed $offset): mixed
	{
		return $this->get($offset);
	}

	public function offsetSet(mixed $offset, mixed $value): void
	{
		if ($offset === null) {
			$this->items[] = $value;

			return;
		}

		$this->set($offset, $value);
	}

	public function offsetUnset(mixed $offset): void
	{
		$this->delete($offset);
	}

	public function count(): int
	{
		return count($this->items);
	}

	/**
	 * @return ArrayIterator<array-key, mixed>
	 */
	public function getIterator(): Traversable
	{
		return new ArrayIterator($this->items);
	}

	/**
	 * @return array<array-key, mixed>
	 */
	public function jsonSerialize(): array
	{
		return $this->items;
	}
}
