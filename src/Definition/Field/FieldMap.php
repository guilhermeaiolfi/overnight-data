<?php

declare(strict_types=1);

namespace ON\Data\Definition\Field;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use ON\Data\Definition\DefinitionInterface;
use ON\Data\Definition\Exception\DefinitionNameConflictException;
use ON\Data\Definition\Exception\FieldException;
use ON\Data\Definition\Exception\InvalidDefinitionClassException;
use ON\Data\Definition\Internal\DefinitionFactory;
use Traversable;

/**
 * @implements IteratorAggregate<string, FieldInterface>
 */
final class FieldMap implements IteratorAggregate, Countable
{
	/** @var array<string, mixed> */
	private array $items;

	/** @var array<string, FieldInterface> */
	private array $fields = [];

	/**
	 * @param array<string, mixed> $items
	 */
	public function __construct(
		private DefinitionInterface $parent,
		array &$items,
	) {
		$this->items = &$items;
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
		if (! $this->has($name) || ! is_array($this->items[$name])) {
			throw new FieldException("Undefined field `{$name}`.");
		}

		if (! isset($this->fields[$name])) {
			$items = &$this->items[$name];
			$this->fields[$name] = DefinitionFactory::restoreField($this->parent, $name, $items);
		}

		return $this->fields[$name];
	}

	public function createOrReturn(string $name, string $class, array $values = []): FieldInterface
	{
		if ($this->parent->hasRelation($name)) {
			throw new DefinitionNameConflictException(
				sprintf(
					"Definition '%s' member name '%s' is already used by a relation.",
					$this->parent->getName(),
					$name
				)
			);
		}

		if ($this->has($name)) {
			$field = $this->get($name);
			if ($field::class !== $class) {
				throw new InvalidDefinitionClassException(
					sprintf("Cannot redefine field '%s' with class '%s'; stored class is '%s'.", $name, $class, $field::class)
				);
			}

			return $field;
		}

		$this->items[$name] = [];
		$items = &$this->items[$name];
		$this->fields[$name] = DefinitionFactory::createField($this->parent, $name, $items, $class, $values);

		return $this->fields[$name];
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

	public function getIterator(): Traversable
	{
		$fields = [];
		foreach (array_keys($this->items) as $name) {
			$fields[$name] = $this->get((string) $name);
		}

		return new ArrayIterator($fields);
	}
}
