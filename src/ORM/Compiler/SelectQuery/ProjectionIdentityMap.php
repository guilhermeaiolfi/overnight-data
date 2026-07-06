<?php

declare(strict_types=1);

namespace ON\Data\ORM\Compiler\SelectQuery;

use ON\Data\Definition\Collection\CollectionInterface;

final class ProjectionIdentityMap
{
	/**
	 * @var array<string, array<string, string>>
	 */
	private array $entries = [];

	public function add(CollectionInterface $collection, string $fieldName, string $resultKey): void
	{
		$this->entries[$collection->getName()][$fieldName] = $resultKey;
	}

	public function get(CollectionInterface $collection, string $fieldName): ?string
	{
		return $this->entries[$collection->getName()][$fieldName] ?? null;
	}

	public function isEmpty(): bool
	{
		return $this->entries === [];
	}

	/**
	 * @return array<string, array<string, string>>
	 */
	public function all(): array
	{
		return $this->entries;
	}
}
