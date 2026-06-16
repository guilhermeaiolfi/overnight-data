<?php

declare(strict_types=1);

namespace ON\Data\Definition\Metadata;

use ArrayIterator;
use IteratorAggregate;
use ON\Data\Support\DefinitionNode;
use Traversable;

final class MetadataMap extends DefinitionNode implements IteratorAggregate
{
	protected static function definitionDefaults(): array
	{
		return [];
	}

	/**
	 * @param array<string, mixed>|null $metadata
	 */
	public function __construct(?array &$metadata = null)
	{
		if ($metadata === null) {
			parent::__construct();

			return;
		}

		parent::__construct([]);
		$this->bind($metadata);
	}

	public function has(array|int|string $key): bool
	{
		return parent::has($key);
	}

	public function get(string|int|null $key = null, mixed $default = null): mixed
	{
		return parent::get($key, $default);
	}

	public function set(array|int|string $key, mixed $value = null): static
	{
		parent::set($key, $value);

		return $this;
	}

	public function remove(string $key): self
	{
		$this->delete($key);

		return $this;
	}

	public function all(): array
	{
		return parent::all();
	}

	public function getIterator(): Traversable
	{
		return new ArrayIterator($this->all());
	}
}
