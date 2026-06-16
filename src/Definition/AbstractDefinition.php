<?php

declare(strict_types=1);

namespace ON\Data\Definition;

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

	public function __construct(protected Registry $registry)
	{
		parent::__construct();
		$this->initializeChildMaps();
	}

	public function __clone()
	{
		$items = self::detachArray($this->all());
		$this->setArray($items);
		$this->initializeChildMaps();
		$this->metadataMap = null;
	}

	public function getRegistry(): Registry
	{
		return $this->registry;
	}

	public function field(string $name, ?string $type = null): FieldInterface
	{
		if ($this->fields->has($name)) {
			return $this->fields->get($name);
		}

		$class = $this->defaultFieldClass();
		$field = new $class($this);
		$field->name($name);
		if ($type !== null) {
			$field->type($type);
		}
		$this->fields->set($name, $field);
		$field = $this->fields->get($name);

		return $field;
	}

	public function getField(string $name): ?FieldInterface
	{
		return $this->fields->has($name) ? $this->fields->get($name) : null;
	}

	public function hasField(string $name): bool
	{
		return $this->fields->has($name);
	}

	public function getFields(): FieldMap
	{
		return $this->fields;
	}

	public function relation(string $name, string $type): RelationInterface
	{
		$relation = new $type($this);
		$relation->name($name);
		$this->relations->replace($name, $relation);
		$relation = $this->relations->get($name);

		return $relation;
	}

	public function getRelation(string $name): ?RelationInterface
	{
		return $this->relations->has($name) ? $this->relations->get($name) : null;
	}

	public function hasRelation(string $name): bool
	{
		return $this->relations->has($name);
	}

	public function getRelations(): RelationMap
	{
		return $this->relations;
	}

	public function end(): Registry
	{
		return $this->registry;
	}

	/**
	 * @return class-string<FieldInterface>
	 */
	protected function defaultFieldClass(): string
	{
		return Field::class;
	}

	protected function afterBindDefinitionArray(): void
	{
		$this->initializeChildMaps();
		$this->metadataMap = null;
	}

	private function initializeChildMaps(): void
	{
		$fieldItems = &$this->items['fields'];
		$relationItems = &$this->items['relations'];
		$this->fields = new FieldMap($this, $fieldItems);
		$this->relations = new RelationMap($this, $relationItems);
	}
}
