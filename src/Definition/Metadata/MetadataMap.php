<?php

declare(strict_types=1);

namespace ON\Data\Definition\Metadata;

use ArrayIterator;
use IteratorAggregate;
use Traversable;

final class MetadataMap implements IteratorAggregate
{
	/** @var array<string, mixed> */
	private array $metadata = [];

	public function has(string $key): bool
	{
		return isset($this->metadata[$key]);
	}

	public function get(string $key, mixed $default = null): mixed
	{
		return $this->metadata[$key] ?? $default;
	}

	public function set(string $key, mixed $value): self
	{
		$this->metadata[$key] = $value;

		return $this;
	}

	public function remove(string $key): self
	{
		unset($this->metadata[$key]);

		return $this;
	}

	public function all(): array
	{
		return $this->metadata;
	}

	public function getIterator(): Traversable
	{
		return new ArrayIterator($this->metadata);
	}
}
