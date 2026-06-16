<?php

declare(strict_types=1);

namespace ON\Data\Definition;

use ON\Data\Definition\Field\FieldInterface;
use ON\Data\Definition\Field\FieldMap;
use ON\Data\Definition\Relation\RelationInterface;
use ON\Data\Definition\Relation\RelationMap;

interface DefinitionInterface
{
	public function getName(): string;

	public function getRegistry(): Registry;

	public function field(string $name, ?string $type = null): FieldInterface;

	public function getField(string $name): ?FieldInterface;

	public function hasField(string $name): bool;

	public function getFields(): FieldMap;

	/**
	 * @template T of RelationInterface
	 * @param class-string<T> $type
	 * @return T
	 */
	public function relation(string $name, string $type): RelationInterface;

	public function getRelation(string $name): ?RelationInterface;

	public function hasRelation(string $name): bool;

	public function getRelations(): RelationMap;

	public function metadata(string $key, mixed $value = null): mixed;

	public function end(): Registry;
}
