<?php

declare(strict_types=1);

namespace ON\Data\Definition\Field;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Display\DisplayInterface;
use ON\Data\Definition\Display\RawDisplay;
use ON\Data\Definition\Interface\InterfaceInterface;

interface FieldInterface
{
	/**
	 * @template T of DisplayInterface
	 * @param class-string<T> $type
	 * @return T
	 */
	public function display(string $type = RawDisplay::class): DisplayInterface;

	public function getDisplay(): DisplayInterface;

	/**
	 * @template T of InterfaceInterface
	 * @param class-string<T> $className
	 * @return T
	 */
	public function interface(string $className): InterfaceInterface;

	public function getInterface(): InterfaceInterface;

	public function name(string $name): self;

	public function getName(): string;

	public function alias(string $alias): self;

	public function getAlias(): string;

	public function setGeneratedFromRelation(?string $name): self;

	public function getGeneratedFromRelation(): ?string;

	public function type(string $type): self;

	public function getType(): string;

	public function column(string $column): self;

	public function getColumn(): string;

	public function hidden(bool $hidden): self;

	public function isHidden(): bool;

	public function primaryKey(bool $pk): self;

	public function isPrimaryKey(): bool;

	public function autoIncrement(bool $autoIncrement): self;

	public function isAutoIncrement(): bool;

	public function nullable(bool $nullable): self;

	public function isNullable(): bool;

	public function unique(bool $unique): self;

	public function isUnique(): bool;

	public function indexed(bool $indexed): self;

	public function isIndexed(): bool;

	public function comment(string $comment): self;

	public function getComment(): ?string;

	public function numericPrecision(int $numericPrecision): self;

	public function getNumericPrecision(): int;

	public function filterable(bool $filterable = true): self;

	public function isFilterable(): bool;

	public function searchable(bool $searchable = true): self;

	public function isSearchable(): ?bool;

	public function sensible(bool $sensible): self;

	public function getSensible(): bool;

	public function required(bool $required): self;

	public function isRequired(): bool;

	public function hasTypecast(): bool;

	/**
	 * @param array<mixed>|string|null $typecast
	 */
	public function typecast(array|string|null $typecast): self;

	/**
	 * @return array<mixed>|string|null
	 */
	public function getTypecast(): array|string|null;

	/**
	 * Set validation rules (pipe-delimited string, e.g. 'required|email|max:255').
	 *
	 * @param array<string, string> $messages Custom messages keyed by rule shorthand
	 *                                        (e.g. 'required'), field name, field:rule, or rule.*
	 */
	public function validation(?string $rules, array $messages = []): self;

	public function getValidation(): ?string;

	/**
	 * @return array<string, string>
	 */
	public function getValidationMessages(): array;

	public function description(?string $description): self;

	public function getDescription(): ?string;

	public function end(): CollectionInterface;
}
