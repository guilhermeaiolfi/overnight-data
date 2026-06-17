<?php

declare(strict_types=1);

namespace ON\Data\Mapper;

final readonly class MappingNode
{
	/**
	 * @param list<mixed>|null $childArguments
	 */
	public function __construct(
		private string|int $name,
		private mixed $value,
		private MappingContext $context,
		private mixed $arguments = null,
		private bool $hasChildMapping = false,
		private mixed $childTarget = null,
		private bool $childCollection = false,
		private ?array $childArguments = null,
	) {
	}

	public function getName(): string|int
	{
		return $this->name;
	}

	public function getValue(): mixed
	{
		return $this->value;
	}

	public function getContext(): MappingContext
	{
		return $this->context;
	}

	public function getArguments(): mixed
	{
		return $this->arguments;
	}

	public function hasChildMapping(): bool
	{
		return $this->hasChildMapping;
	}

	public function getChildTarget(): mixed
	{
		return $this->childTarget;
	}

	public function isChildCollection(): bool
	{
		return $this->childCollection;
	}

	/**
	 * @return list<mixed>
	 */
	public function getChildArguments(): array
	{
		return $this->childArguments ?? $this->context->getArguments();
	}

	public function withContext(MappingContext $context): self
	{
		return new self(
			$this->name,
			$this->value,
			$context,
			$this->arguments,
			$this->hasChildMapping,
			$this->childTarget,
			$this->childCollection,
			$this->childArguments,
		);
	}

	/**
	 * @param list<mixed>|null $arguments
	 */
	public function forChild(
		mixed $target,
		bool $collection = false,
		?array $arguments = null,
	): self {
		return new self(
			$this->name,
			$this->value,
			$this->context,
			$this->arguments,
			true,
			$target,
			$collection,
			$arguments,
		);
	}
}
