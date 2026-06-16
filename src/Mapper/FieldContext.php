<?php

declare(strict_types=1);

namespace ON\Data\Mapper;

use ON\Data\Definition\Field\Field;
use ON\Data\Definition\Field\FieldInterface;

final class FieldContext
{
	/**
	 * @param class-string<FieldTypeInterface>|non-empty-string $type
	 * @param array<string, mixed> $metadata
	 */
	public function __construct(
		private readonly string $name,
		private readonly string $type,
		private readonly bool $nullable = false,
		private readonly ?FieldInterface $field = null,
		private readonly array $metadata = [],
	) {
	}

	public static function fromField(FieldInterface $field): self
	{
		$metadata = [
			'alias' => $field->getAlias(),
			'column' => $field->getColumn(),
			'generatedFromRelation' => $field->getGeneratedFromRelation(),
			'required' => $field->isRequired(),
			'searchable' => $field->isSearchable(),
			'sensible' => $field->getSensible(),
			'description' => $field->getDescription(),
			'typecast' => $field->getTypecast(),
			'validation' => $field->getValidation(),
			'validationMessages' => $field->getValidationMessages(),
			'filterable' => $field->isFilterable(),
			'autoIncrement' => $field->isAutoIncrement(),
			'unique' => $field->isUnique(),
			'indexed' => $field->isIndexed(),
			'comment' => $field->getComment(),
			'numericPrecision' => $field->getNumericPrecision(),
			'isPrimaryKey' => $field->isPrimaryKey(),
		];

		if ($field instanceof Field) {
			$metadata['default'] = $field->getDefault();
			$metadata['castDefault'] = $field->castDefault();
			$metadata['dataType'] = $field->getDataType();
			$metadata['defaultValue'] = $field->getDefaultValue();
			$metadata['maxLength'] = $field->getMaxLength();
		}

		return new self(
			name: $field->getName(),
			type: $field->getType(),
			nullable: $field->isNullable(),
			field: $field,
			metadata: $metadata,
		);
	}

	/**
	 * @param class-string<FieldTypeInterface>|non-empty-string $type
	 * @param array<string, mixed> $metadata
	 */
	public static function named(
		string $name,
		string $type,
		bool $nullable = false,
		array $metadata = [],
	): self {
		return new self($name, $type, $nullable, null, $metadata);
	}

	public function getName(): string
	{
		return $this->name;
	}

	public function getType(): string
	{
		return $this->type;
	}

	public function isNullable(): bool
	{
		return $this->nullable;
	}

	public function hasField(): bool
	{
		return $this->field !== null;
	}

	public function getField(): ?FieldInterface
	{
		return $this->field;
	}

	public function getMetadata(string $key, mixed $default = null): mixed
	{
		return $this->metadata[$key] ?? $default;
	}

	public function isClassType(): bool
	{
		return class_exists($this->type);
	}
}
