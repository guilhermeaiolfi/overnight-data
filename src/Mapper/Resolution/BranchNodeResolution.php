<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Resolution;

final readonly class BranchNodeResolution implements BranchNodeResolutionInterface
{
	/**
	 * @param list<mixed> $arguments
	 */
	public function __construct(
		private string $name,
		private mixed $target,
		private array $arguments,
		private bool $collection = false,
	) {
	}

	/**
	 * @param list<mixed> $arguments
	 */
	public static function named(
		string $name,
		mixed $target,
		array $arguments,
		bool $collection = false,
	): self {
		return new self($name, $target, $arguments, $collection);
	}

	public function getName(): string
	{
		return $this->name;
	}

	public function getTarget(): mixed
	{
		return $this->target;
	}

	public function getArguments(): array
	{
		return $this->arguments;
	}

	public function isCollection(): bool
	{
		return $this->collection;
	}
}
