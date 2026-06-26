<?php

declare(strict_types=1);

namespace ON\Data\Definition\Collection;

use ON\Data\Definition\AbstractDefinition;
use ON\Data\Definition\Exception\InvalidPrimaryKeyException;
use ON\Data\Definition\Exception\PrimaryKeyNotDefinedException;
use ON\Data\Definition\Field\FieldInterface;
use ON\Data\Definition\Relation\BuiltInRelationTypes;
use ON\Data\Definition\Relation\RelationInterface;
use ON\Data\Key;
use stdClass;

class Collection extends AbstractDefinition implements CollectionInterface
{
	protected static function definitionDefaults(): array
	{
		return [
			'class' => static::class,
			'table' => '',
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
			'primaryKey' => [],
			'metadata' => [],
			'fields' => [],
			'relations' => [],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function defaultDefinition(string $name): array
	{
		return static::createDefinition([
			'table' => $name,
		]);
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

	/**
	 * @template T of RelationInterface
	 * @param class-string<T> $type
	 * @return T
	 */
	public function relation(string $name, string $type = BuiltInRelationTypes::DEFAULT): RelationInterface
	{
		return parent::relation($name, $type);
	}

	public function hasMany(string $name, string $targetCollection): RelationInterface
	{
		$relation = $this->relation($name, BuiltInRelationTypes::hasMany());
		$relation->collection($targetCollection);

		return $relation;
	}

	public function hasOne(string $name, string $targetCollection): RelationInterface
	{
		$relation = $this->relation($name, BuiltInRelationTypes::hasOne());
		$relation->collection($targetCollection);

		return $relation;
	}

	public function belongsTo(string $name, string $targetCollection): RelationInterface
	{
		$relation = $this->relation($name, BuiltInRelationTypes::belongsTo());
		$relation->collection($targetCollection);

		return $relation;
	}

	public function primaryKey(string ...$fieldNames): self
	{
		if ($fieldNames === []) {
			throw new InvalidPrimaryKeyException(
				sprintf("Collection '%s' primaryKey() requires at least one field name.", $this->getName())
			);
		}

		$primaryKey = [];
		foreach ($fieldNames as $fieldName) {
			$fieldName = trim($fieldName);
			if ($fieldName === '') {
				throw new InvalidPrimaryKeyException(
					sprintf("Collection '%s' primaryKey() cannot contain empty field names.", $this->getName())
				);
			}

			if (in_array($fieldName, $primaryKey, true)) {
				throw new InvalidPrimaryKeyException(
					sprintf("Collection '%s' primaryKey() cannot contain duplicate field '%s'.", $this->getName(), $fieldName)
				);
			}

			$primaryKey[] = $fieldName;
		}

		$this->set('primaryKey', $primaryKey);

		return $this;
	}

	public function hasPrimaryKey(): bool
	{
		$primaryKey = $this->get('primaryKey');

		return is_array($primaryKey) && $primaryKey !== [];
	}

	public function getPrimaryKey(): array
	{
		$primaryKey = $this->get('primaryKey');
		if (! is_array($primaryKey) || $primaryKey === []) {
			throw new PrimaryKeyNotDefinedException(
				sprintf("Collection '%s' does not define a primary key.", $this->getName())
			);
		}

		$normalized = [];
		foreach ($primaryKey as $fieldName) {
			if (! is_string($fieldName) || $fieldName === '') {
				throw new InvalidPrimaryKeyException(
					sprintf("Collection '%s' contains an invalid primary key field name.", $this->getName())
				);
			}

			$normalized[] = $fieldName;
		}

		/** @var non-empty-list<string> $normalized */
		return $normalized;
	}

	public function getPrimaryKeyFields(): array
	{
		$fields = [];
		foreach ($this->getPrimaryKey() as $fieldName) {
			$fields[] = $this->fields->get($fieldName);
		}

		/** @var non-empty-list<FieldInterface> $fields */
		return $fields;
	}

	public function getPrimaryKeyColumns(): array
	{
		$columns = [];
		foreach ($this->getPrimaryKeyFields() as $field) {
			$columns[] = $field->getColumn();
		}

		/** @var non-empty-list<string> $columns */
		return $columns;
	}

	public function isCompositePrimaryKey(): bool
	{
		return count($this->getPrimaryKey()) > 1;
	}

	public function getKey(Key|array|string|int|float|bool $value): Key
	{
		$primaryKey = $this->getPrimaryKey();

		if ($value instanceof Key) {
			if ($value->getCollection() === $this) {
				return $value;
			}

			if ($value->getCollection()->getName() !== $this->getName()) {
				throw new InvalidPrimaryKeyException(
					sprintf(
						"Primary key belongs to collection '%s', expected '%s'.",
						$value->getCollection()->getName(),
						$this->getName()
					)
				);
			}

			return new Key($this, $value->getValues());
		}

		if (is_array($value)) {
			if (array_is_list($value)) {
				if (count($value) !== count($primaryKey)) {
					throw new InvalidPrimaryKeyException(
						sprintf(
							"Collection '%s' expects %d primary key value(s), %d given.",
							$this->getName(),
							count($primaryKey),
							count($value)
						)
					);
				}

				return new Key($this, array_combine($primaryKey, array_values($value)) ?: []);
			}

			return new Key($this, $this->canonicalizeKeyInput($value));
		}

		if (count($primaryKey) !== 1) {
			throw new InvalidPrimaryKeyException(
				sprintf("Collection '%s' requires %d primary key values.", $this->getName(), count($primaryKey))
			);
		}

		return new Key($this, [$primaryKey[0] => $value]);
	}

	public function getKeyFromRecord(array $record, bool $allowColumnNames = true): Key
	{
		return new Key($this, $this->canonicalizeKeyInput($record, $allowColumnNames, true, false));
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

	public function setFileDefinitionLocation(?string $file = null): void
	{
		$this->set('fileLocation', $file);
	}

	public function getFileDefinitionLocation(): ?string
	{
		$value = $this->get('fileLocation');

		return is_string($value) ? $value : null;
	}

	/**
	 * @param array<string, mixed> $input
	 * @return non-empty-array<string, string|int|float|bool>
	 */
	private function canonicalizeKeyInput(
		array $input,
		bool $allowColumnNames = true,
		bool $ignoreExtraFields = false,
		bool $rejectDuplicateFieldAndColumn = true,
	): array {
		$values = [];
		$usedKeys = [];
		$primaryKeyNames = $this->getPrimaryKey();

		foreach ($this->getPrimaryKeyFields() as $field) {
			$fieldName = $field->getName();
			$columnName = $field->getColumn();
			$hasFieldName = array_key_exists($fieldName, $input);
			$hasColumnName = $allowColumnNames && array_key_exists($columnName, $input);

			if ($hasFieldName && $hasColumnName && $fieldName !== $columnName) {
				if ($rejectDuplicateFieldAndColumn) {
					throw new InvalidPrimaryKeyException(
						sprintf(
							"Primary key for collection '%s' cannot define both field '%s' and column '%s'.",
							$this->getName(),
							$fieldName,
							$columnName
						)
					);
				}

				if ($input[$fieldName] !== $input[$columnName]) {
					throw new InvalidPrimaryKeyException(
						sprintf(
							"Primary key record for collection '%s' contains conflicting values for field '%s' and column '%s'.",
							$this->getName(),
							$fieldName,
							$columnName
						)
					);
				}
			}

			if ($hasFieldName) {
				$usedKeys[] = $fieldName;
				$values[$fieldName] = $input[$fieldName];

				continue;
			}

			if ($hasColumnName) {
				if ($fieldName !== $columnName && $this->fields->has($columnName) && ! in_array($columnName, $primaryKeyNames, true)) {
					throw new InvalidPrimaryKeyException(
						sprintf(
							"Column '%s' is ambiguous for collection '%s' primary key extraction.",
							$columnName,
							$this->getName()
						)
					);
				}

				$usedKeys[] = $columnName;
				$values[$fieldName] = $input[$columnName];

				continue;
			}

			throw new InvalidPrimaryKeyException(
				sprintf("Missing primary key field '%s' for collection '%s'.", $fieldName, $this->getName())
			);
		}

		if (! $ignoreExtraFields) {
			foreach (array_keys($input) as $inputKey) {
				if (! in_array((string) $inputKey, $usedKeys, true)) {
					throw new InvalidPrimaryKeyException(
						sprintf("Unexpected primary key field '%s' for collection '%s'.", (string) $inputKey, $this->getName())
					);
				}
			}
		}

		foreach ($values as $fieldName => $fieldValue) {
			if (! is_string($fieldValue) && ! is_int($fieldValue) && ! is_float($fieldValue) && ! is_bool($fieldValue)) {
				throw new InvalidPrimaryKeyException(
					sprintf("Primary key field '%s' for collection '%s' must be a scalar string|int|float|bool.", $fieldName, $this->getName())
				);
			}
		}

		/** @var non-empty-array<string, string|int|float|bool> $values */
		return $values;
	}
}
