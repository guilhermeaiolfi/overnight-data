<?php

declare(strict_types=1);

namespace ON\Data\Definition\Relation;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\DefinitionInterface;
use ON\Data\Definition\Display\DisplayInterface;
use ON\Data\Definition\Display\RawDisplay;
use ON\Data\Definition\Field\FieldInterface;
use ON\Data\Definition\Interface\InterfaceInterface;
use ON\Data\Query\Relation\Loader\LoaderInterface;

interface RelationInterface
{
	public function display(string $type = RawDisplay::class): DisplayInterface;

	public function getDisplay(): DisplayInterface;

	public function interface(string $className): InterfaceInterface;

	public function getInterface(): InterfaceInterface;

	public function getParent(): DefinitionInterface;

	public function getName(): string;

	public function collection(string $collectionName): self;

	public function getCollectionName(): string;

	public function getCollection(): CollectionInterface;

	public function nullable(bool $nullable): self;

	public function isNullable(): bool;

	public function cascade(bool $cascade): self;

	public function isCascade(): bool;

	public function load(string $load): self;

	public function getLoadStrategy(): string;

	public function innerKey(string|array $fieldName): self;

	public function getInnerKey(): string|array;

	public function innerKeys(): array;

	public function getInnerField(): FieldInterface;

	public function outerKey(string|array $fieldName): self;

	public function getOuterKey(): string|array;

	public function outerKeys(): array;

	public function getOuterField(): FieldInterface;

	public function loader(string $loader): self;

	/**
	 * @return class-string<LoaderInterface>
	 */
	public function getLoader(): string;

	public function where(array $where): self;

	public function getWhere(): array;

	public function orderBy(array $orderBy): self;

	public function getOrderBy(): array;

	/**
	 * Returns 'single' or 'many' — whether this relation resolves to one item or a collection.
	 */
	public function getCardinality(): string;

	/**
	 * Whether this is a junction/pivot relation (M2M) that uses a through table.
	 */
	public function isJunction(): bool;

	public function end(): DefinitionInterface;
}
