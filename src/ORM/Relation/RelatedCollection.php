<?php

declare(strict_types=1);

namespace ON\Data\ORM\Relation;

use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RepresentationBinding;

final class RelatedCollection
{
	/**
	 * Known in-memory relation members. This may be only a partial view of the
	 * database relation when the collection is unloaded or partially loaded.
	 *
	 * @var array<int, object>
	 */
	private array $knownItems = [];
	/** @var array<int, object> */
	private array $baselineKnownItems = [];
	/** @var array<int, object> */
	private array $added = [];
	/** @var array<int, object> */
	private array $removed = [];
	private RelationCollectionState $state;

	/**
	 * @param list<object> $items
	 */
	public function __construct(
		private readonly RecordState $owner,
		private readonly string $relationName,
		private readonly RepresentationBinding $childBinding,
		RelationCollectionState $state = RelationCollectionState::UNLOADED,
		array $items = [],
	) {
		if (trim($relationName) === '') {
			throw new StateException('Relation collection name cannot be empty.');
		}

		foreach ($items as $item) {
			if (! is_object($item)) {
				throw new StateException(sprintf(
					"Relation collection '%s' can only contain objects.",
					$relationName
				));
			}

			$this->knownItems[spl_object_id($item)] = $item;
		}

		$this->baselineKnownItems = $this->knownItems;
		$this->state = $state === RelationCollectionState::UNLOADED && $this->knownItems !== []
			? RelationCollectionState::PARTIALLY_LOADED
			: $state;
	}

	public function getOwner(): RecordState
	{
		return $this->owner;
	}

	public function getRelationName(): string
	{
		return $this->relationName;
	}

	public function getChildBinding(): RepresentationBinding
	{
		return $this->childBinding;
	}

	public function getRelatedBinding(): RepresentationBinding
	{
		return $this->childBinding;
	}

	public function getState(): RelationCollectionState
	{
		return $this->state;
	}

	public function isUnloaded(): bool
	{
		return $this->state === RelationCollectionState::UNLOADED;
	}

	public function isPartiallyLoaded(): bool
	{
		return $this->state === RelationCollectionState::PARTIALLY_LOADED;
	}

	public function isFullyLoaded(): bool
	{
		return $this->state === RelationCollectionState::FULLY_LOADED;
	}

	public function markPartiallyLoaded(): void
	{
		$this->state = RelationCollectionState::PARTIALLY_LOADED;
	}

	public function markFullyLoaded(): void
	{
		$this->state = RelationCollectionState::FULLY_LOADED;
	}

	public function add(object $item): void
	{
		$id = spl_object_id($item);

		unset($this->removed[$id]);

		if (array_key_exists($id, $this->knownItems)) {
			return;
		}

		$this->knownItems[$id] = $item;
		if ($this->state === RelationCollectionState::UNLOADED) {
			$this->state = RelationCollectionState::PARTIALLY_LOADED;
		}

		if (! array_key_exists($id, $this->baselineKnownItems)) {
			$this->added[$id] = $item;
		}
	}

	public function remove(object $item): void
	{
		$id = spl_object_id($item);
		$isKnown = array_key_exists($id, $this->knownItems);

		if ($isKnown) {
			unset($this->knownItems[$id]);
		}

		if (array_key_exists($id, $this->added)) {
			unset($this->added[$id]);

			return;
		}

		$this->removed[$id] = $item;
	}

	public function contains(object $item): bool
	{
		return array_key_exists(spl_object_id($item), $this->knownItems);
	}

	/**
	 * @return list<object>
	 */
	public function getItems(): array
	{
		return array_values($this->knownItems);
	}

	/**
	 * @return list<object>
	 */
	public function getAdded(): array
	{
		return array_values($this->added);
	}

	/**
	 * @return list<object>
	 */
	public function getRemoved(): array
	{
		return array_values($this->removed);
	}

	public function hasChanges(): bool
	{
		return $this->added !== [] || $this->removed !== [];
	}

	public function clearChanges(): void
	{
		$this->added = [];
		$this->removed = [];
		$this->baselineKnownItems = $this->knownItems;
	}

	public function countKnown(): int
	{
		return count($this->knownItems);
	}

	public function isEmptyKnown(): bool
	{
		return $this->knownItems === [];
	}
}
