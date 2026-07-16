<?php

declare(strict_types=1);

namespace ON\Data\ORM\Relation;

use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Record\RecordState;
use ON\Data\ORM\Representation\Schema\RepresentationSchema;

final class ToManyRelationState implements RelationStateInterface
{
	private const LOAD_UNLOADED = 'unloaded';
	private const LOAD_PARTIAL = 'partial';
	private const LOAD_FULL = 'full';

	/**
	 * Known in-memory relation members. This may be only a partial view of the
	 * database relation when the collection is unloaded or partially loaded.
	 *
	 * @var array<string, RelationTarget>
	 */
	private array $knownItems = [];
	/** @var array<string, RelationTarget> */
	private array $baselineKnownItems = [];
	/** @var array<string, RelationTarget> */
	private array $added = [];
	/** @var array<string, RelationTarget> */
	private array $removed = [];
	private string $loadState = self::LOAD_UNLOADED;

	/**
	 * @param list<object> $items
	 */
	public function __construct(
		private readonly RecordState $owner,
		private readonly string $relationName,
		private readonly RepresentationSchema $childSchema,
		array $items = [],
	) {
		if (trim($relationName) === '') {
			throw new StateException('To-many relation name cannot be empty.');
		}

		foreach ($items as $item) {
			if (! is_object($item)) {
				throw new StateException(sprintf(
					"To-many relation '%s' can only contain objects.",
					$relationName
				));
			}

			$target = RelationTarget::from($item);
			$this->knownItems[$target->identityKey()] = $target;
		}

		$this->baselineKnownItems = $this->knownItems;
		if ($this->knownItems !== []) {
			$this->markPartiallyLoaded();
		}
	}

	/**
	 * @param list<object> $items
	 */
	public static function full(
		RecordState $owner,
		string $relationName,
		RepresentationSchema $childSchema,
		array $items = [],
	): self {
		$state = new self($owner, $relationName, $childSchema, $items);
		$state->markFullyLoaded();

		return $state;
	}

	public function getOwner(): RecordState
	{
		return $this->owner;
	}

	public function getRelationName(): string
	{
		return $this->relationName;
	}

	public function getRelatedSchema(): RepresentationSchema
	{
		return $this->childSchema;
	}

	public function isUnloaded(): bool
	{
		return $this->loadState === self::LOAD_UNLOADED;
	}

	public function isPartiallyLoaded(): bool
	{
		return $this->loadState === self::LOAD_PARTIAL;
	}

	public function isFullyLoaded(): bool
	{
		return $this->loadState === self::LOAD_FULL;
	}

	public function markPartiallyLoaded(): void
	{
		if ($this->isFullyLoaded()) {
			return;
		}

		$this->loadState = self::LOAD_PARTIAL;
	}

	public function markFullyLoaded(): void
	{
		$this->loadState = self::LOAD_FULL;
	}

	public function add(object $item): void
	{
		$this->addTarget(RelationTarget::from($item));
	}

	public function addTarget(RelationTarget $target): void
	{
		$id = $target->identityKey();

		unset($this->removed[$id]);

		if (array_key_exists($id, $this->knownItems)) {
			return;
		}

		$this->knownItems[$id] = $target;
		if ($this->isUnloaded()) {
			$this->markPartiallyLoaded();
		}

		if (! array_key_exists($id, $this->baselineKnownItems)) {
			$this->added[$id] = $target;
		}
	}

	public function remove(object $item): void
	{
		$this->removeTarget(RelationTarget::from($item));
	}

	public function removeTarget(RelationTarget $target): void
	{
		$id = $target->identityKey();
		$isKnown = array_key_exists($id, $this->knownItems);

		if ($isKnown) {
			unset($this->knownItems[$id]);
		}

		if (array_key_exists($id, $this->added)) {
			unset($this->added[$id]);

			return;
		}

		$this->removed[$id] = $target;
	}

	public function contains(object $item): bool
	{
		return array_key_exists(RelationTarget::from($item)->identityKey(), $this->knownItems);
	}

	public function containsTarget(RelationTarget $target): bool
	{
		return array_key_exists($target->identityKey(), $this->knownItems);
	}

	/**
	 * @return list<object>
	 */
	public function getItems(): array
	{
		return array_values(array_map(
			static fn (RelationTarget $target): object => $target->toObject(),
			$this->knownItems,
		));
	}

	/**
	 * @return list<RelationTarget>
	 */
	public function getItemTargets(): array
	{
		return array_values($this->knownItems);
	}

	/**
	 * @return list<object>
	 */
	public function getAdded(): array
	{
		return array_values(array_map(
			static fn (RelationTarget $target): object => $target->toObject(),
			$this->added,
		));
	}

	/**
	 * @return list<RelationTarget>
	 */
	public function getAddedTargets(): array
	{
		return array_values($this->added);
	}

	/**
	 * @return list<object>
	 */
	public function getRemoved(): array
	{
		return array_values(array_map(
			static fn (RelationTarget $target): object => $target->toObject(),
			$this->removed,
		));
	}

	/**
	 * @return list<RelationTarget>
	 */
	public function getRemovedTargets(): array
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
