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
use ON\Data\Support\DefinitionNode;
use stdClass;

class Collection extends DefinitionNode implements CollectionInterface
{
	use MetadataTrait;

	public FieldMap $fields;

	public RelationMap $relations;

	public function __construct(
		protected Registry $registry,
		?array &$items = null,
	) {
		if ($items === null) {
			parent::__construct();
		} else {
			parent::__construct([]);
			$this->bind($items);
		}

		$fieldItems = &$this->items['fields'];
		$relationItems = &$this->items['relations'];
		$this->fields = new FieldMap($this, $fieldItems);
		$this->relations = new RelationMap($this, $relationItems);
	}

	protected static function definitionDefaults(): array
	{
		return static::defaultDefinition('');
	}

	public function bindDefinitionArray(array &$items): void
	{
		$this->bind($items);
		$fieldItems = &$this->items['fields'];
		$relationItems = &$this->items['relations'];
		$this->fields = new FieldMap($this, $fieldItems);
		$this->relations = new RelationMap($this, $relationItems);
		$this->metadataMap = null;
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function defaultDefinition(string $name): array
	{
		return [
			'class' => static::class,
			'name' => $name,
			'table' => $name,
			'database' => 'default',
			'entity' => stdClass::class,
			'parentCollection' => null,
			'scope' => null,
			'repository' => null,
			'mapper' => null,
			'source' => null,
			'note' => null,
			'description' => null,
			'hidden' => false,
			'fileLocation' => null,
			'metadata' => [],
			'fields' => [],
			'relations' => [],
		];
	}

	public function __clone()
	{
		$items = $this->all();
		$this->setArray($items);
		$fieldItems = &$this->items['fields'];
		$relationItems = &$this->items['relations'];
		$this->fields = new FieldMap($this, $fieldItems);
		$this->relations = new RelationMap($this, $relationItems);
		$this->metadataMap = null;
	}

	public function table(string $table): self
	{
		$this->set('table', $table);

		return $this;
	}

	public function getTable(): string
	{
		return (string) $this->get('table');
	}

	public function entity(string $entity): self
	{
		$this->set('entity', $entity);

		return $this;
	}

	public function getEntity(): string
	{
		return (string) $this->get('entity');
	}

	public function database(string $database): self
	{
		$this->set('database', $database);

		return $this;
	}

	public function getDatabase(): string
	{
		return (string) $this->get('database');
	}

	public function parentCollection(string $parentCollection): self
	{
		$this->set('parentCollection', $parentCollection);

		return $this;
	}

	public function getParentCollection(): ?string
	{
		$value = $this->get('parentCollection');

		return is_string($value) ? $value : null;
	}

	public function scope(string $scope): self
	{
		$this->set('scope', $scope);

		return $this;
	}

	public function getScope(): ?string
	{
		$value = $this->get('scope');

		return is_string($value) ? $value : null;
	}

	public function repository(?string $repository): self
	{
		$this->set('repository', $repository);

		return $this;
	}

	public function getRepository(): ?string
	{
		$value = $this->get('repository');

		return is_string($value) ? $value : null;
	}

	public function mapper(?string $mapper): self
	{
		$this->set('mapper', $mapper);

		return $this;
	}

	public function getMapper(): ?string
	{
		$value = $this->get('mapper');

		return is_string($value) ? $value : null;
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

	public function note(string $note): self
	{
		$this->set('note', $note);

		return $this;
	}

	public function getNote(): ?string
	{
		$value = $this->get('note');

		return is_string($value) ? $value : null;
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

	public function source(?string $source): self
	{
		$this->set('source', $source);

		return $this;
	}

	public function getSource(): ?string
	{
		$value = $this->get('source');

		return is_string($value) ? $value : null;
	}

	public function hidden(bool $hidden): self
	{
		$this->set('hidden', $hidden);

		return $this;
	}

	public function isHidden(): bool
	{
		return (bool) $this->get('hidden');
	}

	public function field(string $name, ?string $type = null): FieldInterface
	{
		if ($this->fields->has($name)) {
			return $this->fields->get($name);
		}

		$field = new Field($this);
		$this->fields->set($name, $field);
		$field = $this->fields->get($name);
		$field->name($name);
		if ($type !== null) {
			$field->type($type);
		}

		return $field;
	}

	/**
	 * @template T
	 * @param class-string<T> $type
	 * @return T
	 */
	public function relation(string $name, string $type = HasOneRelation::class): RelationInterface
	{
		$relation = new $type($this);
		$this->relations->set($name, $relation);
		$relation = $this->relations->get($name);
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
		foreach ($this->fields as $field) {
			if ($field->isPrimaryKey()) {
				$pk[] = $field;
			}
		}

		if (count($pk) === 1) {
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
		$this->set('fileLocation', $file);
	}

	public function getFileDefinitionLocation(): ?string
	{
		$value = $this->get('fileLocation');

		return is_string($value) ? $value : null;
	}
}
