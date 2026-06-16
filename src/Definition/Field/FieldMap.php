<?php

declare(strict_types=1);

namespace ON\Data\Definition\Field;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use ON\Data\Definition\DefinitionInterface;
use ON\Data\Definition\Exception\FieldException;
use ON\Data\Definition\Internal\DefinitionFactory;
use Traversable;

/**
 * @implements IteratorAggregate<string, FieldInterface>
 */
final class FieldMap implements IteratorAggregate, Countable
{
	/** @var array<string, mixed> */
	private array $items = [];

	/** @var array<string, FieldInterface> */
	private array $fields = [];

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
		foreach ($this->fields as $name => $field) {
			$this->fields[$name] = clone $field;
			$this->items[$name] = $this->fields[$name]->all();
		}
	}

	public function count(): int
	{
		return count($this->items);
	}

	public function getColumnNames(): array
	{
		return array_values(array_map(static function (FieldInterface $field) {
			return $field->getColumn();
		}, iterator_to_array($this)));
	}

	public function getFieldNameColumnNameMap(): array
	{
		$data = [];
		foreach ($this as $field) {
			$data[$field->getName()] = $field->getColumn();
		}

		return $data;
	}

	public function getNames(): array
	{
		return array_keys($this->items);
	}

	public function has(string $name): bool
	{
		return array_key_exists($name, $this->items);
	}

	public function hasColumn(string $name): bool
	{
		foreach ($this as $field) {
			if ($field->getColumn() === $name) {
				return true;
			}
		}

		return false;
	}

	public function get(string $name): FieldInterface
	{
		if (! $this->has($name)) {
			throw new FieldException("Undefined field `{$name}`.");
		}

		if (isset($this->fields[$name])) {
			return $this->fields[$name];
		}

		if ($this->parent === null || ! is_array($this->items[$name])) {
			throw new FieldException("Undefined field `{$name}`.");
		}

		$items = &$this->items[$name];
		$field = DefinitionFactory::field($this->parent, $items);
		$this->fields[$name] = $field;

		return $field;
	}

	public function getKeyByColumnName(string $name): string
	{
		foreach ($this as $key => $field) {
			if ($field->getColumn() === $name) {
				return $key;
			}
		}

		throw new FieldException("Undefined field with column name `{$name}`.");
	}

	public function getByColumnName(string $name): FieldInterface
	{
		foreach ($this as $field) {
			if ($field->getColumn() === $name) {
				return $field;
			}
		}

		throw new FieldException("Undefined field with column name `{$name}`.");
	}

	public function set(string $name, FieldInterface $field): self
	{
		if ($this->has($name)) {
			throw new FieldException("Field `{$name}` already exists.");
		}

		$this->items[$name] = $field instanceof Field ? $field->all() : [];
		unset($this->fields[$name]);
		if ($this->parent !== null && is_array($this->items[$name])) {
			$items = &$this->items[$name];
			$this->fields[$name] = DefinitionFactory::field($this->parent, $items);
		} else {
			$this->fields[$name] = $field;
		}

		return $this;
	}

	public function remove(string $name): self
	{
		unset($this->items[$name], $this->fields[$name]);

		return $this;
	}

	public function replace(string $name, FieldInterface $field): self
	{
		$this->items[$name] = $field instanceof Field ? $field->all() : [];
		unset($this->fields[$name]);
		if ($this->parent !== null && is_array($this->items[$name])) {
			$items = &$this->items[$name];
			$this->fields[$name] = DefinitionFactory::field($this->parent, $items);
		}

		return $this;
	}

	public function getIterator(): Traversable
	{
		$fields = [];
		foreach (array_keys($this->items) as $name) {
			$fields[$name] = $this->get((string) $name);
		}

		return new ArrayIterator($fields);
	}
}
