<?php

declare(strict_types=1);

namespace ON\Data\Definition\Field;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Display\DisplayTrait;
use ON\Data\Definition\Exception\FieldException;
use ON\Data\Definition\Interface\InterfaceTrait;
use ON\Data\Definition\MetadataTrait;
use ON\Data\Support\DefinitionNode;

class Field extends DefinitionNode implements FieldInterface
{
	use DisplayTrait;
	use InterfaceTrait;
	use SchemaTrait;
	use MetadataTrait;

	public function __construct(
		protected CollectionInterface $collection,
		?array &$items = null,
	) {
		if ($items === null) {
			parent::__construct();
		} else {
			parent::__construct([]);
			$this->bind($items);
		}
	}

	protected static function definitionDefaults(): array
	{
		return [
			'class' => static::class,
			'name' => '',
			'column' => null,
			'type' => null,
			'alias' => null,
			'required' => false,
			'searchable' => null,
			'sensible' => false,
			'default' => null,
			'castDefault' => false,
			'generatedFromRelation' => null,
			'validation' => null,
			'validationMessages' => [],
			'description' => null,
			'typecast' => null,
			'metadata' => [],
			'nullable' => false,
			'hidden' => false,
			'unique' => false,
			'indexed' => false,
			'max_length' => 255,
			'numeric_precision' => 2,
			'default_value' => null,
			'data_type' => null,
			'comment' => null,
			'auto_increment' => false,
			'filterable' => true,
		];
	}

	public function __clone()
	{
		$this->setArray($this->all());
		$this->display = null;
		$this->interface = null;
		$this->metadataMap = null;
	}

	public function setGeneratedFromRelation(?string $relation_name): self
	{
		$this->set('generatedFromRelation', $relation_name);

		return $this;
	}

	public function getGeneratedFromRelation(): ?string
	{
		$value = $this->get('generatedFromRelation');

		return is_string($value) ? $value : null;
	}

	public function default(mixed $default, bool $castDefault = true): self
	{
		$this->set('default', $default);
		$this->set('castDefault', $castDefault);

		return $this;
	}

	public function getDefault(): mixed
	{
		return $this->get('default');
	}

	public function hasDefault(): bool
	{
		return $this->get('default') !== null;
	}

	public function castDefault(): bool
	{
		return (bool) $this->get('castDefault');
	}

	public function name(string $name): self
	{
		$this->set('name', $name);

		return $this;
	}

	public function getName(): string
	{
		return (string) $this->get('name');
	}

	public function alias(string $alias): self
	{
		$this->set('alias', $alias);

		return $this;
	}

	public function getAlias(): string
	{
		$alias = $this->get('alias');

		return is_string($alias) ? $alias : $this->getName();
	}

	public function type(string $type): self
	{
		$this->set('type', $type);

		return $this;
	}

	public function getType(): string
	{
		$type = $this->get('type');
		if (! is_string($type) || $type === '') {
			throw new FieldException('Field(' . $this->getName() . ') type must be set in collection: ' . $this->collection->getName());
		}

		return $type;
	}

	public function sensible(bool $sensible): self
	{
		$this->set('sensible', $sensible);
		if ($sensible) {
			$this->hidden(true);
		}

		return $this;
	}

	public function getSensible(): bool
	{
		return (bool) $this->get('sensible');
	}

	public function column(string $column): self
	{
		$this->set('column', $column);

		return $this;
	}

	public function getColumn(): string
	{
		$column = $this->get('column');

		return is_string($column) ? $column : $this->getName();
	}

	public function required(bool $required): self
	{
		$this->set('required', $required);

		return $this;
	}

	public function isRequired(): bool
	{
		return (bool) $this->get('required');
	}

	public function searchable(bool $searchable = true): self
	{
		$this->set('searchable', $searchable);

		return $this;
	}

	public function isSearchable(): ?bool
	{
		$value = $this->get('searchable');

		return is_bool($value) ? $value : null;
	}

	public function hasTypecast(): bool
	{
		return $this->get('typecast') !== null;
	}

	public function typecast(array|string|null $typecast): self
	{
		$this->set('typecast', $typecast);

		return $this;
	}

	public function getTypecast(): array|string|null
	{
		$value = $this->get('typecast');

		return is_array($value) || is_string($value) || $value === null ? $value : null;
	}

	public function validation(?string $rules, array $messages = []): self
	{
		$this->set('validation', $rules);
		$this->set('validationMessages', $rules === null ? [] : $messages);

		return $this;
	}

	public function getValidation(): ?string
	{
		$value = $this->get('validation');

		return is_string($value) ? $value : null;
	}

	public function getValidationMessages(): array
	{
		$value = $this->get('validationMessages');

		return is_array($value) ? $value : [];
	}

	public function description(?string $description): self
	{
		$this->set('description', $description);

		return $this;
	}

	public function getDescription(): ?string
	{
		$value = $this->get('description');

		return is_string($value) ? $value : null;
	}

	public function end(): CollectionInterface
	{
		return $this->collection;
	}
}
