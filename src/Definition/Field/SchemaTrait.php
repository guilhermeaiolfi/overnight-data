<?php

declare(strict_types=1);

namespace ON\Data\Definition\Field;

use ON\Data\Definition\Relation\RelationInterface;

trait SchemaTrait
{
	protected bool $nullable = false;

	protected bool $hidden = false;

	protected bool $unique = false;

	protected bool $indexed = false;

	protected int $max_length = 255;

	protected int $numeric_precision = 2;

	protected mixed $default_value = null;

	protected ?string $data_type = null;

	protected ?string $comment = null;

	protected bool $pk = false;

	protected bool $auto_increment = false;

	protected bool $filterable = true;

	public function numericPrecision(int $numeric_precision): self
	{
		$this->numeric_precision = $numeric_precision;

		return $this;
	}

	public function getNumericPrecision(): int
	{
		return $this->numeric_precision;
	}

	public function autoIncrement(bool $auto_increment): self
	{
		$this->auto_increment = $auto_increment;

		return $this;
	}

	public function isAutoIncrement(): bool
	{
		return $this->auto_increment;
	}

	public function primaryKey(bool $pk): self
	{
		$this->pk = $pk;
		if ($pk) {
			$this->filterable = false;
		}

		return $this;
	}

	public function isPrimaryKey(): bool
	{
		return $this->pk;
	}

	public function filterable(bool $filterable = true): self
	{
		$this->filterable = $filterable;

		return $this;
	}

	public function isFilterable(): bool
	{
		return $this->filterable;
	}

	public function dataType(mixed $data_type): self
	{
		$this->data_type = $data_type ;

		return $this;
	}

	public function getDataType(): mixed
	{
		return $this->data_type;
	}

	public function defaultValue(mixed $default_value): self
	{
		$this->default_value = $default_value ;

		return $this;
	}

	public function getDefaultValue(): mixed
	{
		return $this->default_value;
	}

	public function maxLength(int $max_length): self
	{
		$this->max_length = $max_length ;

		return $this;
	}

	public function getMaxLength(): int
	{
		return $this->max_length;
	}

	public function nullable(bool $nullable): self
	{
		$this->nullable = $nullable;

		return $this;
	}

	public function isNullable(): bool
	{
		return $this->nullable;
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

	public function unique(bool $unique): self
	{
		$this->unique = $unique;

		return $this;
	}

	public function isUnique(): bool
	{
		return $this->unique;
	}

	public function indexed(bool $indexed): self
	{
		$this->indexed = $indexed;

		return $this;
	}

	public function isIndexed(): bool
	{
		return $this->indexed;
	}

	public function comment(string $comment): self
	{
		$this->comment = $comment;

		return $this;
	}

	public function getComment(): ?string
	{
		return $this->comment;
	}

	public function end(): FieldInterface|RelationInterface
	{
		return $this->parent;
	}
}
