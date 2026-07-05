<?php

declare(strict_types=1);

namespace ON\Data\ORM\Relation;

use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\State\RecordState;

final class RelatedCollectionStore
{
	/** @var array<string, RelatedCollection> */
	private array $collections = [];

	public function add(RelatedCollection $collection): void
	{
		$key = $this->key($collection->getOwner(), $collection->getRelationName());
		if (isset($this->collections[$key])) {
			if ($this->collections[$key] === $collection) {
				return;
			}

			throw new StateException(sprintf(
				"Related collection store already contains a different collection for relation '%s'.",
				$collection->getRelationName()
			));
		}

		$this->collections[$key] = $collection;
	}

	public function has(RecordState $owner, string $relationName): bool
	{
		return array_key_exists($this->key($owner, $relationName), $this->collections);
	}

	public function get(RecordState $owner, string $relationName): ?RelatedCollection
	{
		return $this->collections[$this->key($owner, $relationName)] ?? null;
	}

	public function remove(RecordState $owner, string $relationName): void
	{
		unset($this->collections[$this->key($owner, $relationName)]);
	}

	public function clear(): void
	{
		$this->collections = [];
	}

	/**
	 * @return list<RelatedCollection>
	 */
	public function getAll(): array
	{
		return array_values($this->collections);
	}

	/**
	 * @return list<RelatedCollection>
	 */
	public function getChanged(): array
	{
		return array_values(array_filter(
			$this->collections,
			static fn (RelatedCollection $collection): bool => $collection->hasChanges()
		));
	}

	private function key(RecordState $owner, string $relationName): string
	{
		if (trim($relationName) === '') {
			throw new StateException('Relation collection name cannot be empty.');
		}

		return $owner->getStateHash() . "\0" . $relationName;
	}
}
