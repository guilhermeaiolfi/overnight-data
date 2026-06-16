<?php

declare(strict_types=1);

namespace ON\Data\Definition\Metadata;

use ArrayIterator;
use IteratorAggregate;
use ON\Data\Support\Dot;
use Traversable;

final class MetadataMap extends Dot implements IteratorAggregate
{
	/**
	 * @param array<string, mixed>|null $metadata
	 */
	public function __construct(?array &$metadata = null)
	{
		parent::__construct([]);

		if ($metadata !== null) {
			$this->setReference($metadata);
		}
	}

	public function remove(string $key): self
	{
		$this->delete($key);

		return $this;
	}

	public function getIterator(): Traversable
	{
		return new ArrayIterator($this->all());
	}
}
