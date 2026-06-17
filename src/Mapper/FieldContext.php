<?php

declare(strict_types=1);

namespace ON\Data\Mapper;

use ON\Data\Definition\Field\FieldInterface;

final readonly class FieldContext
{
	/**
	 * @param class-string<FieldTypeInterface>|non-empty-string $type
	 */
	public function __construct(
		private readonly string $name,
		private readonly string $type,
		private readonly bool $nullable = false,
	) {
	}

	public static function fromField(FieldInterface $field): self
	{
		return new self(
			name: $field->getName(),
			type: $field->getType(),
			nullable: $field->isNullable(),
		);
	}

	/**
	 * @param class-string<FieldTypeInterface>|non-empty-string $type
	 */
	public static function named(
		string $name,
		string $type,
		bool $nullable = false,
	): self {
		return new self($name, $type, $nullable);
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

	public function isClassType(): bool
	{
		return class_exists($this->type);
	}
}
