<?php

declare(strict_types=1);

namespace ON\Data\Definition\Collection;

use ON\Data\Definition\Field\Field;
use ON\Data\Definition\Field\FieldInterface;
use ON\Data\Definition\Field\FieldMap;
use ON\Data\Definition\MetadataTrait;
use ON\Data\Definition\Registry;
use ON\Data\Definition\Relation\BelongsToRelation;
use ON\Data\Definition\Relation\HasManyRelation;
use ON\Data\Definition\Relation\HasOneRelation;
use ON\Data\Definition\Relation\RelationInterface;
use ON\Data\Definition\Relation\RelationMap;
use stdClass;

class Collection implements CollectionInterface
{
	use MetadataTrait;
	protected string $name;
	protected ?string $note = null;
	protected ?string $description = null;
	protected ?string $source = null;
	protected bool $hidden = false;
	protected ?string $mapper = null;
	protected string $database = "default";
	protected ?string $parentCollection = null;
	protected string $entity = stdClass::class;
	protected ?string $table = null;
	public FieldMap $fields;
	public RelationMap $relations;
	protected ?string $fileLocation = null;

	public function __construct(
		protected Registry $registry
	) {
		$this->fields = new FieldMap();
		$this->relations = new RelationMap();
	}

	/**
	 * @var class-string|null
	 */
	protected ?string $scope = null;

	/**
	 * @var class-string|null
	 */
	private ?string $repository = null;

	public function table(string $table): self
	{
		$this->table = $table;

		return $this;
	}

	public function getTable(): string
	{
		return $this->table;
	}

	public function entity(string $entity): self
	{
		$this->entity = $entity;

		return $this;
	}

	public function getEntity(): string
	{
		return $this->entity;
	}

	public function database(string $database): self
	{
		$this->database = $database;

		return $this;
	}

	public function getDatabase(): string
	{
		return $this->database;
	}

	public function parentCollection(string $parentCollection): self
	{
		$this->parentCollection = $parentCollection;

		return $this;
	}

	public function getParentCollection(): ?string
	{
		return $this->parentCollection;
	}

	public function scope(string $scope): self
	{
		$this->scope = $scope;

		return $this;
	}

	public function getScope(): ?string
	{
		return $this->scope;
	}

	public function repository(?string $repository): self
	{
		$this->repository = $repository;

		return $this;
	}

	public function getRepository(): ?string
	{
		return $this->repository;
	}

	public function mapper(?string $mapper): self
	{
		$this->mapper = $mapper;

		return $this;
	}

	public function getMapper(): ?string
	{
		return $this->mapper;
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

	public function note(string $note): self
	{
		$this->note = $note;

		return $this;
	}

	public function getNote(): ?string
	{
		return $this->note;
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

	public function source(?string $source): self
	{
		$this->source = $source;

		return $this;
	}

	public function getSource(): ?string
	{
		return $this->source;
	}

	public function hidden(bool $hidden): self
	{
		$this->hidden = $hidden;

		return $this;
	}

	public function isHidden(): bool
	{
		return $this->hidden;
	}

	public function field(string $name, ?string $type = null): FieldInterface
	{
		$field = null;

		// it could get in here when generating fields because of relations
		// but that field could also be a primary field that's already defined
		if ($this->fields->has($name)) {
			$field = $this->fields->get($name);
		} else {
			$field = new Field($this);
			$this->fields->set($name, $field);
			if (isset($name)) {
				$field->name($name);
			}
			if (isset($type)) {
				$field->type($type);
			}
		}

		return $field;
	}

	/**
	 * @template T
	 * @param class-string<T> $type
	 * @return T
	 * */
	public function relation(string $name, string $type = HasOneRelation::class): RelationInterface
	{
		$relation = new $type($this);
		$this->relations->set($name, $relation);
		$relation->name($name);

		return $relation;
	}

	public function hasMany(string $name, string $targetCollection): HasManyRelation
	{
		/** @var HasManyRelation $relation */
		$relation = $this->relation($name, HasManyRelation::class);
		$relation->collection($targetCollection);

		return $relation;
	}

	public function hasOne(string $name, string $targetCollection): HasOneRelation
	{
		/** @var HasOneRelation $relation */
		$relation = $this->relation($name, HasOneRelation::class);
		$relation->collection($targetCollection);

		return $relation;
	}

	public function belongsTo(string $name, string $targetCollection): BelongsToRelation
	{
		/** @var BelongsToRelation $relation */
		$relation = $this->relation($name, BelongsToRelation::class);
		$relation->collection($targetCollection);

		return $relation;
	}

	/** @return FieldInterface[]|FieldInterface */
	public function getPrimaryKeyFields(): mixed
	{
		$pk = [];
		foreach ($this->fields as $name => $field) {
			if ($field->isPrimaryKey()) {
				$pk[] = $field;
			}
		}
		if (count($pk) == 1) {
			return $pk[0];
		}

		return $pk;
	}

	public function getPrimaryKey(): PrimaryKeyDefinition
	{
		$primary = $this->getPrimaryKeyFields();
		if ($primary instanceof FieldInterface) {
			return new PrimaryKeyDefinition($this, [$primary]);
		}

		return new PrimaryKeyDefinition(
			$this,
			array_values(array_filter($primary, static fn (mixed $field): bool => $field instanceof FieldInterface))
		);
	}

	public function getVisibleFields(): array
	{
		$visible = [];
		foreach ($this->fields as $fieldName => $field) {
			if (! $field->isHidden()) {
				$visible[] = (string) $fieldName;
			}
		}

		return $visible;
	}

	public function getVisibleColumns(): array
	{
		$columns = [];
		foreach ($this->getVisibleFields() as $fieldName) {
			$columns[] = $this->fields->get($fieldName)->getColumn();
		}

		return $columns;
	}

	public function getFieldNameByColumn(string $columnName): string
	{
		return $this->fields->hasColumn($columnName)
			? $this->fields->getKeyByColumnName($columnName)
			: $columnName;
	}

	public function mapRowFromColumns(array $row): array
	{
		$mapped = [];
		foreach ($row as $column => $value) {
			$mapped[$this->getFieldNameByColumn((string) $column)] = $value;
		}

		return $mapped;
	}

	public function mapVisibleRowFromColumns(array $row): array
	{
		$item = [];
		foreach ($this->fields as $fieldName => $field) {
			if ($field->isHidden()) {
				continue;
			}

			$column = $field->getColumn();
			if (array_key_exists($column, $row)) {
				$item[(string) $fieldName] = $row[$column];
			}
		}

		return $item;
	}

	public function end(): Registry
	{
		return $this->registry;
	}

	public function getRegistry(): Registry
	{
		return $this->registry;
	}

	public function setFileDefinitionLocation(?string $file = null): void
	{
		$this->fileLocation = $file;
	}

	public function getFileDefinitionLocation(): ?string
	{
		return $this->fileLocation;
	}
}
