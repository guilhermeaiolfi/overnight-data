<?php

declare(strict_types=1);

namespace ON\Data\Definition\Field;

use ON\Data\Definition\Relation\RelationInterface;

trait SchemaTrait
{
	public static function schemaDefaults(): array
	{
		return [
			'nullable' => false,
			'hidden' => false,
			'unique' => false,
			'indexed' => false,
			'max_length' => 255,
			'numeric_precision' => 2,
			'default_value' => null,
			'data_type' => null,
			'comment' => null,
			'pk' => false,
			'auto_increment' => false,
			'filterable' => true,
		];
	}

	public function numericPrecision(int $numeric_precision): self
	{
		$this->set('numeric_precision', $numeric_precision);

		return $this;
	}

	public function getNumericPrecision(): int
	{
		return (int) $this->get('numeric_precision');
	}

	public function autoIncrement(bool $auto_increment): self
	{
		$this->set('auto_increment', $auto_increment);

		return $this;
	}

	public function isAutoIncrement(): bool
	{
		return (bool) $this->get('auto_increment');
	}

	public function primaryKey(bool $pk): self
	{
		$this->set('pk', $pk);
		if ($pk) {
			$this->set('filterable', false);
		}

		return $this;
	}

	public function isPrimaryKey(): bool
	{
		return (bool) $this->get('pk');
	}

	public function filterable(bool $filterable = true): self
	{
		$this->set('filterable', $filterable);

		return $this;
	}

	public function isFilterable(): bool
	{
		return (bool) $this->get('filterable');
	}

	public function dataType(mixed $data_type): self
	{
		$this->set('data_type', $data_type);

		return $this;
	}

	public function getDataType(): mixed
	{
		return $this->get('data_type');
	}

	public function defaultValue(mixed $default_value): self
	{
		$this->set('default_value', $default_value);

		return $this;
	}

	public function getDefaultValue(): mixed
	{
		return $this->get('default_value');
	}

	public function maxLength(int $max_length): self
	{
		$this->set('max_length', $max_length);

		return $this;
	}

	public function getMaxLength(): int
	{
		return (int) $this->get('max_length');
	}

	public function nullable(bool $nullable): self
	{
		$this->set('nullable', $nullable);

		return $this;
	}

	public function isNullable(): bool
	{
		return (bool) $this->get('nullable');
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

	public function unique(bool $unique): self
	{
		$this->set('unique', $unique);

		return $this;
	}

	public function isUnique(): bool
	{
		return (bool) $this->get('unique');
	}

	public function indexed(bool $indexed): self
	{
		$this->set('indexed', $indexed);

		return $this;
	}

	public function isIndexed(): bool
	{
		return (bool) $this->get('indexed');
	}

	public function comment(string $comment): self
	{
		$this->set('comment', $comment);

		return $this;
	}

	public function getComment(): ?string
	{
		$value = $this->get('comment');

		return is_string($value) ? $value : null;
	}

	public function end(): FieldInterface|RelationInterface
	{
		return $this->parent;
	}
}
