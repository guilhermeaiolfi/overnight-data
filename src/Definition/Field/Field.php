<?php

declare(strict_types=1);

namespace ON\Data\Definition\Field;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Display\DisplayTrait;
use ON\Data\Definition\Exception\FieldException;
use ON\Data\Definition\Interface\InterfaceTrait;
use ON\Data\Definition\MetadataTrait;

class Field implements FieldInterface
{
	use DisplayTrait;
	use InterfaceTrait;
	use SchemaTrait;
	use MetadataTrait;

	protected string $name;

	protected ?string $column = null;

	protected ?string $type = null;

	protected ?string $alias = null;

	protected bool $required = false;

	protected ?bool $searchable = null;

	protected bool $sensible = false;

	protected mixed $default = null;

	protected bool $castDefault = false;

	protected ?string $generatedFromRelation = null;

	protected ?string $validation = null;

	/** @var array<string, string> */
	protected array $validationMessages = [];

	protected ?string $description = null;

	/**
	 * @var callable-array|string|null
	 */
	private array|string|null $typecast = null;

	public function __construct(
		protected CollectionInterface $collection
	) {

	}

	public function setGeneratedFromRelation(?string $relation_name): self
	{
		$this->generatedFromRelation = $relation_name;

		return $this;
	}

	public function getGeneratedFromRelation(): ?string
	{
		return $this->generatedFromRelation;
	}

	public function default(mixed $default, bool $castDefault = true): self
	{
		$this->default = $default;

		$this->castDefault = $castDefault;

		return $this;
	}

	public function getDefault(): mixed
	{
		return $this->default;
	}

	public function hasDefault(): bool
	{
		return $this->default !== null;
	}

	public function castDefault(): bool
	{
		return $this->castDefault;
	}

	public function name(string $name): self
	{
		$this->name = $name;

		return $this;
	}

	public function getName(): string
	{
		return $this->name;
	}

	public function alias(string $alias): self
	{
		$this->alias = $alias;

		return $this;
	}

	public function getAlias(): string
	{
		return $this->alias ?? $this->name;
	}

	public function type(string $type): self
	{
		$this->type = $type;

		return $this;
	}

	public function getType(): string
	{
		if (empty($this->type)) {

			throw new FieldException('Field(' . $this->getName() . ') type must be set in collection: ' . $this->collection->getName());
		}

		return $this->type;
	}

	public function sensible(bool $sensible): self
	{
		$this->sensible = $sensible;
		if ($sensible) {
			$this->hidden(true);
		}

		return $this;
	}

	public function getSensible(): bool
	{
		return $this->sensible;
	}

	public function column(string $column): self
	{
		$this->column = $column;

		return $this;
	}

	public function getColumn(): string
	{
		if (! isset($this->column)) {
			return $this->name;
		}

		return $this->column;
	}

	public function required(bool $required): self
	{
		$this->required = $required;

		return $this;
	}

	public function isRequired(): bool
	{
		return $this->required;
	}

	public function searchable(bool $searchable = true): self
	{
		$this->searchable = $searchable;

		return $this;
	}

	public function isSearchable(): ?bool
	{
		return $this->searchable;
	}

	public function hasTypecast(): bool
	{
		return $this->typecast !== null;
	}

	/**
	 * @param callable-array|string|null $typecast
	 */
	public function typecast(array|string|null $typecast): self
	{
		$this->typecast = $typecast;

		return $this;
	}

	/**
	 * @return callable-array|string|null
	 */
	public function getTypecast(): array|string|null
	{
		return $this->typecast;
	}

	public function validation(?string $rules, array $messages = []): self
	{
		$this->validation = $rules;
		$this->validationMessages = $rules === null ? [] : $messages;

		return $this;
	}

	public function getValidation(): ?string
	{
		return $this->validation;
	}

	public function getValidationMessages(): array
	{
		return $this->validationMessages;
	}

	public function description(?string $description): self
	{
		$this->description = $description;

		return $this;
	}

	public function getDescription(): ?string
	{
		return $this->description;
	}

	public function end(): CollectionInterface
	{
		return $this->collection;
	}
}
