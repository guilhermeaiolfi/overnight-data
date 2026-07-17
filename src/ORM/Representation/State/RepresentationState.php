<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\State;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Record\RecordState;
use ON\Data\ORM\Representation\Schema\RepresentationFieldSchema;
use ON\Data\ORM\Representation\Schema\RepresentationSchema;

final class RepresentationState
{
	/** @var array<string, RepresentationFieldStateItem> */
	private array $fieldItems = [];
	/** @var array<string, RepresentationRelationStateItem> */
	private array $relationItems = [];

	/**
	 * @param array<string, RepresentationFieldStateItem>|list<RepresentationFieldStateItem> $fieldItems
	 * @param array<string, RepresentationRelationStateItem>|list<RepresentationRelationStateItem> $relationItems
	 */
	public function __construct(
		private RepresentationSchema $schema,
		array $fieldItems,
		array $relationItems = [],
	) {
		$this->fieldItems = $this->normalizeFieldItems($fieldItems);
		$this->relationItems = $this->normalizeRelationItems($relationItems);
	}

	/**
	 * @param array<string, RecordState> $recordsBySourceKey
	 */
	public static function fromRecords(
		RepresentationSchema $schema,
		array $recordsBySourceKey,
	): self {
		return new self(
			$schema,
			self::fieldItemsForRecords($schema, $recordsBySourceKey),
			self::relationItemsForRecords($schema, $recordsBySourceKey),
		);
	}

	public function getSchema(): RepresentationSchema
	{
		return $this->schema;
	}

	/**
	 * Resolves the record this representation is rooted at: the concrete record
	 * owned by root source path [], never a same-collection related record.
	 *
	 * Returns null when no root-source item has been attached yet. Throws when
	 * root-source field items disagree on which record they own, which signals an
	 * inconsistent state.
	 */
	public function getRootRecord(): ?RecordState
	{
		$rootRecord = null;

		foreach ($this->fieldItems as $item) {
			if (! $item->getSchema()->isRootSource()) {
				continue;
			}

			$record = $item->getRecord();

			if ($rootRecord instanceof RecordState && $rootRecord->getStateHash() !== $record->getStateHash()) {
				throw new StateException(sprintf(
					"Representation state has inconsistent root records for collection '%s'.",
					$this->schema->getCollectionName()
				));
			}

			$rootRecord = $record;
		}

		return $rootRecord;
	}

	public function requireRootRecord(): RecordState
	{
		$record = $this->getRootRecord();
		if (! $record instanceof RecordState) {
			throw new StateException(sprintf(
				"Representation state has no root record for collection '%s'.",
				$this->schema->getCollectionName()
			));
		}

		return $record;
	}

	public function hasFieldItem(string $path): bool
	{
		return array_key_exists($path, $this->fieldItems);
	}

	public function getFieldItem(string $path): RepresentationFieldStateItem
	{
		if (! array_key_exists($path, $this->fieldItems)) {
			throw new StateException(sprintf("Representation state does not contain field item path '%s'.", $path));
		}

		return $this->fieldItems[$path];
	}

	/**
	 * @return list<RepresentationFieldStateItem>
	 */
	public function getFieldItems(): array
	{
		return array_values($this->fieldItems);
	}

	/**
	 * @return list<RepresentationFieldStateItem>
	 */
	public function getWritableFieldItems(): array
	{
		return array_values(array_filter(
			$this->fieldItems,
			static fn (RepresentationFieldStateItem $item): bool => $item->getSchema()->isWritable()
		));
	}

	public function hasRelationItem(string $path): bool
	{
		return array_key_exists($path, $this->relationItems);
	}

	public function getRelationItem(string $path): RepresentationRelationStateItem
	{
		if (! array_key_exists($path, $this->relationItems)) {
			throw new StateException(sprintf("Representation state does not contain relation item path '%s'.", $path));
		}

		return $this->relationItems[$path];
	}

	/**
	 * @return list<RepresentationRelationStateItem>
	 */
	public function getRelationItems(): array
	{
		return array_values($this->relationItems);
	}

	/**
	 * @return list<RecordState>
	 */
	public function getRecordsForCollection(CollectionInterface $collection): array
	{
		$records = [];
		foreach ($this->fieldItems as $fieldItem) {
			if ($fieldItem->getSchema()->getCollectionName() === $collection->getName()) {
				$record = $fieldItem->getRecord();
				$records[$record->getStateHash()] = $record;
			}
		}

		foreach ($this->relationItems as $relationItem) {
			if ($relationItem->getSchema()->getOwnerCollectionName() === $collection->getName()) {
				$record = $relationItem->getOwnerRecord();
				$records[$record->getStateHash()] = $record;
			}
		}

		return array_values($records);
	}

	/**
	 * All distinct RecordStates referenced by field and relation items.
	 *
	 * @return list<RecordState>
	 */
	public function getUniqueRecords(): array
	{
		$records = [];
		foreach ($this->fieldItems as $item) {
			$record = $item->getRecord();
			$records[$record->getStateHash()] = $record;
		}

		foreach ($this->relationItems as $item) {
			$record = $item->getOwnerRecord();
			$records[$record->getStateHash()] = $record;
		}

		return array_values($records);
	}

	/**
	 * Collapse this representation to one record, or null when no items are attached.
	 *
	 * @throws StateException when field/relation items resolve to multiple records
	 */
	public function getSingleRecord(): ?RecordState
	{
		$records = $this->getUniqueRecords();
		if ($records === []) {
			return null;
		}

		if (count($records) > 1) {
			throw new StateException('Representation state cannot be collapsed to one record.');
		}

		return $records[0];
	}

	/**
	 * Require exactly one concrete record (e.g. Session::remove).
	 */
	public function requireSingleRecord(): RecordState
	{
		$records = $this->getUniqueRecords();
		if ($records === []) {
			throw new StateException('Cannot remove representation because its schema does not resolve to a concrete record state.');
		}

		if (count($records) > 1) {
			throw new StateException('Cannot remove representation because its schema resolves to multiple record states.');
		}

		return $records[0];
	}

	/**
	 * Owner RecordState for a named relation on this representation (detach / relation ops).
	 */
	public function getOwnerRecord(string $relation): RecordState
	{
		if ($this->schema->hasRelation($relation)) {
			$relationSchema = $this->schema->getRelation($relation);
			foreach ($this->relationItems as $item) {
				if ($item->getSchema()->getPath() === $relationSchema->getPath()) {
					return $item->getOwnerRecord();
				}
			}

			$root = $this->getRootRecord();
			if ($root instanceof RecordState) {
				return $root;
			}
		}

		$root = $this->getRootRecord();
		if ($root instanceof RecordState && $root->getCollection()->hasRelation($relation)) {
			return $root;
		}

		$matches = $this->getRecordsForCollection($this->schema->getCollection());
		if (count($matches) === 1) {
			return $matches[0];
		}

		throw new StateException(sprintf(
			"Cannot get owner record for relation '%s' on the tracked representation.",
			$relation,
		));
	}

	/**
	 * Deliberate post-sync acknowledgement only.
	 *
	 * @param array<string, RecordState> $touchedRecords
	 */
	public function acceptSyncedRecords(array $touchedRecords): void
	{
		foreach ($this->fieldItems as $path => $item) {
			$record = $item->getRecord();
			if (! array_key_exists($record->getStateHash(), $touchedRecords)) {
				continue;
			}

			$this->fieldItems[$path] = $item->withBaselineRevision($record->getRevision());
		}
	}

	/**
	 * @param array<string, RecordState> $recordsBySourceKey
	 * @return list<RepresentationFieldStateItem>
	 */
	private static function fieldItemsForRecords(
		RepresentationSchema $schema,
		array $recordsBySourceKey,
	): array {
		$items = [];
		foreach ($schema->getFields() as $fieldSchema) {
			$sourceKey = $fieldSchema->getSourcePathKey();
			$record = $recordsBySourceKey[$sourceKey] ?? null;

			if (! $record instanceof RecordState) {
				if ($fieldSchema->shouldSkipWhenMissing()) {
					continue;
				}

				throw new StateException(sprintf(
					"Cannot create representation state because source path '%s' is unresolved.",
					$sourceKey
				));
			}

			$items[] = RepresentationFieldStateItem::createOne($fieldSchema, $record);
		}

		return $items;
	}

	/**
	 * @param array<string, RecordState> $recordsBySourceKey
	 * @return list<RepresentationRelationStateItem>
	 */
	private static function relationItemsForRecords(RepresentationSchema $schema, array $recordsBySourceKey): array
	{
		if ($schema->getRelations() === []) {
			return [];
		}

		$rootKey = RepresentationFieldSchema::sourcePathKey([]);
		$rootRecord = $recordsBySourceKey[$rootKey] ?? null;
		if (! $rootRecord instanceof RecordState) {
			throw new StateException(sprintf(
				"Cannot create representation state because root source path '%s' is unresolved.",
				$rootKey
			));
		}

		$items = [];
		foreach ($schema->getRelations() as $relationSchema) {
			$items[] = RepresentationRelationStateItem::createOne($relationSchema, $rootRecord);
		}

		return $items;
	}

	/**
	 * @param array<string, RepresentationFieldStateItem>|list<RepresentationFieldStateItem> $items
	 * @return array<string, RepresentationFieldStateItem>
	 */
	private function normalizeFieldItems(array $items): array
	{
		$normalized = [];
		foreach ($items as $item) {
			if (! $item instanceof RepresentationFieldStateItem) {
				throw new StateException('Representation state field items must be RepresentationFieldStateItem instances.');
			}

			$normalized[$item->getPath()] = $item;
		}

		return $normalized;
	}

	/**
	 * @param array<string, RepresentationRelationStateItem>|list<RepresentationRelationStateItem> $items
	 * @return array<string, RepresentationRelationStateItem>
	 */
	private function normalizeRelationItems(array $items): array
	{
		$normalized = [];
		foreach ($items as $item) {
			if (! $item instanceof RepresentationRelationStateItem) {
				throw new StateException('Representation state relation items must be RepresentationRelationStateItem instances.');
			}

			$normalized[$item->getPath()] = $item;
		}

		return $normalized;
	}
}
