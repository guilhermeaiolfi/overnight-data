<?php

declare(strict_types=1);

namespace ON\Data\Mapper;

final readonly class MappingNode
{
	public function __construct(
		private string|int $name,
		private mixed $value,
		private MappingContext $context,
		private mixed $arguments = null,
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

	public function withContext(MappingContext $context): self
	{
		return new self(
			$this->name,
			$this->value,
			$context,
			$this->arguments,
		);
	}
}
