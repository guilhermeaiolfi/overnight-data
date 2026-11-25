<?php

declare(strict_types=1);

namespace ON\Data\ORM\Relation;

use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Record\RecordState;
use ON\Data\Schema\RepresentationSchema;

final class ToManyRelationState implements RelationStateInterface
{
	private const LOAD_UNLOADED = 'unloaded';
	private const LOAD_PARTIAL = 'partial';
	private const LOAD_FULL = 'full';

	/**
	 * Known in-memory relation members. This may be only a partial view of the
	 * database relation when the collection is unloaded or partially loaded.
	 *
	 * @var array<string, object>
	 */
	private array $knownItems = [];
	/** @var array<string, object> */
	private array $baselineKnownItems = [];
	/** @var array<string, object> */
	private array $added = [];
	/** @var array<string, object> */
	private array $removed = [];
	private string $loadState = self::LOAD_UNLOADED;
	/** @var array<string, RecordState> Records inserted before their primary keys were assigned. */
	private array $unkeyedRecords = [];

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

			$key = $this->memberKey($item);
			$this->knownItems[$key] = $item;
			$this->rememberUnkeyedRecord($item, $key);
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

	/**
	 * @param list<object> $items
	 */
	public static function partial(
		RecordState $owner,
		string $relationName,
		RepresentationSchema $childSchema,
		array $items = [],
	): self {
		$state = new self($owner, $relationName, $childSchema, $items);
		$state->markPartiallyLoaded();

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
		$id = $this->memberKey($item);

		unset($this->removed[$id]);

		if (array_key_exists($id, $this->knownItems)) {
			return;
		}

		$this->knownItems[$id] = $item;
		$this->rememberUnkeyedRecord($item, $id);
		if ($this->isUnloaded()) {
			$this->markPartiallyLoaded();
		}

		if (! array_key_exists($id, $this->baselineKnownItems)) {
			$this->added[$id] = $item;
		}
	}

	public function remove(object $item): void
	{
		$id = $this->memberKey($item);
		$isKnown = array_key_exists($id, $this->knownItems);

		if ($isKnown) {
			unset($this->knownItems[$id]);
		}

		if (array_key_exists($id, $this->added)) {
			unset($this->added[$id], $this->unkeyedRecords[$id]);

			return;
		}

		$this->removed[$id] = $item;
		$this->rememberUnkeyedRecord($item, $id);
	}

	public function contains(object $item): bool
	{
		return array_key_exists($this->memberKey($item), $this->knownItems);
	}

	/** A generated key can become available while its record is already in this relation. */
	private function memberKey(object $item): string
	{
		$key = RelationMemberIdentity::key($item);
		if ($item instanceof RecordState && $item->hasKey()) {
			foreach ($this->unkeyedRecords as $storedKey => $member) {
				if (RelationMemberIdentity::same($member, $item)) {
					return $storedKey;
				}
			}
		}

		return $key;
	}

	private function rememberUnkeyedRecord(object $item, string $key): void
	{
		if ($item instanceof RecordState && ! $item->hasKey()) {
			$this->unkeyedRecords[$key] = $item;
		}
	}

	/**
	 * Project a DTO item list into this relation state.
	 *
	 * Partial/unloaded: add only. Fully loaded: add missing and remove absent
	 * representation-backed targets (record-backed pending adds are kept).
	 *
	 * @param list<object> $items
	 */
	public function syncFromItems(array $items): void
	{
		if (! $this->isFullyLoaded()) {
			foreach ($items as $item) {
				$this->add($item);
			}

			return;
		}

		$currentIds = [];
		foreach ($items as $item) {
			$currentIds[$this->memberKey($item)] = true;
			$this->add($item);
		}

		foreach ($this->knownItems as $id => $known) {
			// Record-backed pending adds are not represented in the DTO list.
			if (! $known instanceof RecordState && ! isset($currentIds[$id])) {
				$this->remove($known);
			}
		}
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
		if ($this->unkeyedRecords !== []) {
			$members = $this->knownItems;
			$this->knownItems = $this->unkeyedRecords = [];
			foreach ($members as $member) {
				$key = RelationMemberIdentity::key($member);
				$this->knownItems[$key] = $member;
				$this->rememberUnkeyedRecord($member, $key);
			}
		}
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
