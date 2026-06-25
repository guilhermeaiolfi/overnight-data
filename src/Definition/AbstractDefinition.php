<?php

declare(strict_types=1);

namespace ON\Data\Definition;

use ON\Data\Definition\Exception\DefinitionNameConflictException;
use ON\Data\Definition\Field\Field;
use ON\Data\Definition\Field\FieldInterface;
use ON\Data\Definition\Field\FieldMap;
use ON\Data\Definition\Relation\RelationInterface;
use ON\Data\Definition\Relation\RelationMap;
use ON\Data\Support\DefinitionNode;

abstract class AbstractDefinition extends DefinitionNode implements DefinitionInterface
{
	use MetadataTrait;

	public FieldMap $fields;

	public RelationMap $relations;

	public function field(string $name, ?string $type = null, ?string $class = null): FieldInterface
	{
		$this->fields = $this->getFields();

		return $this->fields->createOrReturn(
			$name,
			$class ?? $this->defaultFieldClass(),
			$type === null ? [] : ['type' => $type],
		);
	}

	public function getField(string $name): ?FieldInterface
	{
		return $this->getFields()->has($name) ? $this->getFields()->get($name) : null;
	}

	public function hasField(string $name): bool
	{
		return $this->getFields()->has($name);
	}

	public function getFields(): FieldMap
	{
		return $this->fields ??= new FieldMap($this, $this->items['fields']);
	}

	public function relation(string $name, string $type): RelationInterface
	{
		$this->relations = $this->getRelations();

		return $this->relations->createOrReturn($name, $type);
	}

	public function getRelation(string $name): ?RelationInterface
	{
		return $this->getRelations()->has($name) ? $this->getRelations()->get($name) : null;
	}

	public function hasRelation(string $name): bool
	{
		return $this->getRelations()->has($name);
	}

	public function getRelations(): RelationMap
	{
		return $this->relations ??= new RelationMap($this, $this->items['relations']);
	}

	public function end(): Registry
	{
		return $this->getRegistry();
	}

	/**
	 * @return class-string<FieldInterface>
	 */
	protected function defaultFieldClass(): string
	{
		return Field::class;
	}

	protected function initializeRuntimeState(): void
	{
		if (isset($this->items['fields']) && is_array($this->items['fields'])) {
			$fieldItems = &$this->items['fields'];
		} else {
			$fieldItems = [];
		}

		if (isset($this->items['relations']) && is_array($this->items['relations'])) {
			$relationItems = &$this->items['relations'];
		} else {
			$relationItems = [];
		}

		$conflicts = array_intersect(array_keys($fieldItems), array_keys($relationItems));
		if ($conflicts !== []) {
			$name = (string) array_values($conflicts)[0];

			throw new DefinitionNameConflictException(
				sprintf(
					"Definition '%s' member name '%s' is used by both a field and a relation.",
					$this->getName(),
					$name
				)
			);
		}

		$this->fields = new FieldMap($this, $fieldItems);
		$this->relations = new RelationMap($this, $relationItems);
	}
}
