<?php

declare(strict_types=1);

namespace ON\Data\ORM\State;

use ON\Data\ORM\Exception\StateException;

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
		private RepresentationBinding $binding,
		array $fieldItems,
		array $relationItems = [],
	) {
		$this->fieldItems = $this->normalizeFieldItems($fieldItems);
		$this->relationItems = $this->normalizeRelationItems($relationItems);
	}

	public function getBinding(): RepresentationBinding
	{
		return $this->binding;
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
			if (! $item->getBinding()->isRootSource()) {
				continue;
			}

			$record = $item->getRecord();

			if ($rootRecord instanceof RecordState && $rootRecord->getStateHash() !== $record->getStateHash()) {
				throw new StateException(sprintf(
					"Representation state has inconsistent root records for collection '%s'.",
					$this->binding->getCollectionName()
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
				$this->binding->getCollectionName()
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
			static fn (RepresentationFieldStateItem $item): bool => $item->getBinding()->isWritable()
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
