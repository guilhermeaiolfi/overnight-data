<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Resolution;

use ON\Data\Definition\Field\FieldInterface;
use ON\Data\Mapper\FieldTypeInterface;

final readonly class LeafNodeResolution implements LeafNodeResolutionInterface
{
	/**
	 * @param class-string<FieldTypeInterface>|non-empty-string|null $type
	 */
	public function __construct(
		private string $name,
		private ?string $type,
		private bool $nullable = false,
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

	public static function passthrough(string $name): self
	{
		return new self($name, null);
	}

	public function getName(): string
	{
		return $this->name;
	}

	public function getType(): ?string
	{
		return $this->type;
	}

	public function isNullable(): bool
	{
		return $this->nullable;
	}
}
