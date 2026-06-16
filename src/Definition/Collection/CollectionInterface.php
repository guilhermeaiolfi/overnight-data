<?php

declare(strict_types=1);

namespace ON\Data\Definition\Collection;

use ON\Data\Definition\DefinitionInterface;
use ON\Data\Definition\Exception\InvalidPrimaryKeyException;
use ON\Data\Definition\Exception\PrimaryKeyNotDefinedException;
use ON\Data\Definition\Field\FieldInterface;
use ON\Data\Definition\Relation\BelongsToRelation;
use ON\Data\Definition\Relation\HasManyRelation;
use ON\Data\Definition\Relation\HasOneRelation;
use ON\Data\Key;

interface CollectionInterface extends DefinitionInterface
{
	public function entity(string $entity): self;

	public function getEntity(): string;

	public function table(string $table): self;

	public function getTable(): string;

	public function scope(string $scope): self;

	public function getScope(): ?string;

	public function source(?string $source): self;

	public function getSource(): ?string;

	public function database(string $database): self;

	public function getDatabase(): string;

	public function repository(?string $repository): self;

	public function getRepository(): ?string;

	public function mapper(?string $mapper): self;

	public function getMapper(): ?string;

	public function name(string $name): self;

	public function hidden(bool $hidden): self;

	public function isHidden(): bool;

	public function hasMany(string $name, string $targetCollection): HasManyRelation;

	public function hasOne(string $name, string $targetCollection): HasOneRelation;

	public function belongsTo(string $name, string $targetCollection): BelongsToRelation;

	public function primaryKey(string ...$fieldNames): self;

	public function hasPrimaryKey(): bool;

	/**
	 * @return non-empty-list<string>
	 *
	 * @throws PrimaryKeyNotDefinedException
	 */
	public function getPrimaryKey(): array;

	/**
	 * @return non-empty-list<FieldInterface>
	 *
	 * @throws PrimaryKeyNotDefinedException
	 */
	public function getPrimaryKeyFields(): array;

	/**
	 * @return non-empty-list<string>
	 *
	 * @throws PrimaryKeyNotDefinedException
	 */
	public function getPrimaryKeyColumns(): array;

	public function isCompositePrimaryKey(): bool;

	/**
	 * @throws InvalidPrimaryKeyException
	 * @throws PrimaryKeyNotDefinedException
	 */
	public function getKey(Key|array|string|int|float|bool $value): Key;

	/**
	 * @param array<string, mixed> $record
	 *
	 * @throws InvalidPrimaryKeyException
	 * @throws PrimaryKeyNotDefinedException
	 */
	public function getKeyFromRecord(array $record, bool $allowColumnNames = true): Key;

	/**
	 * @return list<string>
	 */
	public function getVisibleFields(): array;

	/**
	 * @return list<string>
	 */
	public function getVisibleColumns(): array;

	public function getFieldNameByColumn(string $columnName): string;

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	public function mapRowFromColumns(array $row): array;

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	public function mapVisibleRowFromColumns(array $row): array;

	public function note(string $note): self;

	public function getNote(): ?string;

	public function description(?string $description): self;

	public function getDescription(): ?string;

	public function parentCollection(string $parentCollection): self;

	public function getParentCollection(): ?string;

	public function setFileDefinitionLocation(?string $file = null): void;

	public function getFileDefinitionLocation(): ?string;
}
