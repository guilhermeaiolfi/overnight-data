<?php

declare(strict_types=1);

namespace ON\Data\Mapper\Walker;

/**
 * @internal
 */
final readonly class NestedMapping
{
	/**
	 * @param list<mixed> $arguments
	 */
	public function __construct(
		private mixed $target,
		private bool $collection,
		private array $arguments,
	) {
	}

	public function getTarget(): mixed
	{
		return $this->target;
	}

	public function isCollection(): bool
	{
		return $this->collection;
	}

	/**
	 * @return list<mixed>
	 */
	public function getArguments(): array
	{
		return $this->arguments;
	}
}
