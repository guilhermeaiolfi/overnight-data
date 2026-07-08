<?php

declare(strict_types=1);

namespace ON\Data\ORM\Sync;

use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\Relation\RelationChangeInterface;
use ON\Data\ORM\Relation\RelationStateStore;
use ON\Data\ORM\Relation\ToManyRelationState;
use ON\Data\ORM\Relation\ToOneRelationState;
use ON\Data\ORM\State\RepresentationRelationStateItem;
use ON\Data\ORM\State\RepresentationState;
use ON\Data\ORM\State\RepresentationStateStore;

final class RelationRepresentationSynchronizer
{
	private RepresentationReader $reader;

	public function __construct(?RepresentationReader $reader = null)
	{
		$this->reader = $reader ?? new RepresentationReader();
	}

	/**
	 * @return list<RelationChangeInterface>
	 *
	 * @param RelationStateStore<ToManyRelationState> $toManyRelations
	 * @param RelationStateStore<ToOneRelationState> $toOneRelations
	 */
	public function sync(
		RepresentationStateStore $representations,
		RelationStateStore $toManyRelations,
		RelationStateStore $toOneRelations,
		?RepresentationStateStore $states = null,
	): array {
		$touched = [];
		$touchedIds = [];
		$states ??= $representations;

		foreach ($representations->getAll() as $representation => $state) {
			foreach ($state->getRelationItems() as $relationItem) {
				$relationSchema = $relationItem->getSchema();
				if ($relationSchema->isMany()) {
					$this->syncMany($representation, $relationItem, $toManyRelations, $states, $touched, $touchedIds);

					continue;
				}

				if ($relationSchema->isSingle()) {
					$this->syncOne($representation, $relationItem, $toOneRelations, $states, $touched, $touchedIds);
				}
			}
		}

		return $touched;
	}

	/**
	 * @param list<RelationChangeInterface> $touched
	 * @param array<int, true> $touchedIds
	 * @param RelationStateStore<ToManyRelationState> $toManyRelations
	 */
	private function syncMany(
		object $representation,
		RepresentationRelationStateItem $relationItem,
		RelationStateStore $toManyRelations,
		RepresentationStateStore $states,
		array &$touched,
		array &$touchedIds,
	): void {
		$relationSchema = $relationItem->getSchema();
		$owner = $relationItem->getOwnerRecord();
		$relationName = $relationItem->getRelationName();

		try {
			$items = $this->reader->readItems($representation, $relationSchema, $this->syncError(...));
		} catch (SyncException $exception) {
			if (! $relationSchema->shouldSkipWhenMissing() || ! str_contains($exception->getMessage(), ' is missing.')) {
				throw $exception;
			}

			return;
		}
		foreach ($items as $item) {
			$this->requireTrackedRepresentation($states, $item, $relationSchema->getPath());
		}

		$relation = $toManyRelations->get($owner, $relationName);
		if (! $relation instanceof ToManyRelationState) {
			$relation = new ToManyRelationState($owner, $relationName, $relationSchema->getRelatedSchema());
			$toManyRelations->add($relation);
		}

		$this->applyItems($relation, $items);
		$this->touch($relation, $touched, $touchedIds);
	}

	/**
	 * @param list<RelationChangeInterface> $touched
	 * @param array<int, true> $touchedIds
	 * @param RelationStateStore<ToOneRelationState> $toOneRelations
	 */
	private function syncOne(
		object $representation,
		RepresentationRelationStateItem $relationItem,
		RelationStateStore $toOneRelations,
		RepresentationStateStore $states,
		array &$touched,
		array &$touchedIds,
	): void {
		$relationSchema = $relationItem->getSchema();
		$owner = $relationItem->getOwnerRecord();
		$relationName = $relationItem->getRelationName();

		try {
			$target = $this->reader->readTarget($representation, $relationSchema, $this->syncError(...));
		} catch (SyncException $exception) {
			if (! $relationSchema->shouldSkipWhenMissing() || ! str_contains($exception->getMessage(), ' is missing.')) {
				throw $exception;
			}

			return;
		}
		if ($target !== null) {
			$this->requireTrackedRepresentation($states, $target, $relationSchema->getPath());
		}

		$relation = $toOneRelations->get($owner, $relationName);
		if (! $relation instanceof ToOneRelationState) {
			$relation = new ToOneRelationState(
				$owner,
				$relationName,
				$relationSchema->getRelatedSchema()
			);
			$toOneRelations->add($relation);
		}

		$relation->set($target);
		$this->touch($relation, $touched, $touchedIds);
	}

	private function requireTrackedRepresentation(
		RepresentationStateStore $states,
		object $object,
		string $path,
	): RepresentationState {
		$tracked = $states->get($object);
		if ($tracked instanceof RepresentationState) {
			return $tracked;
		}

		throw new SyncException(sprintf(
			"Representation relation path '%s' references an object that is not tracked; adopt or track the related object before synchronization.",
			$path
		));
	}

	/**
	 * @param non-empty-string $message
	 */
	private function syncError(string $message): SyncException
	{
		return new SyncException($message);
	}

	/**
	 * @param list<RelationChangeInterface> $touched
	 * @param array<int, true> $touchedIds
	 */
	private function touch(RelationChangeInterface $change, array &$touched, array &$touchedIds): void
	{
		$id = spl_object_id($change);
		if (! array_key_exists($id, $touchedIds)) {
			$touchedIds[$id] = true;
			$touched[] = $change;
		}
	}

	/**
	 * @param list<object> $items
	 */
	private function applyItems(ToManyRelationState $relation, array $items): void
	{
		if (! $relation->isFullyLoaded()) {
			foreach ($items as $item) {
				$relation->add($item);
			}

			return;
		}

		$currentIds = [];
		$addedIds = [];
		foreach ($relation->getAdded() as $added) {
			$addedIds[spl_object_id($added)] = true;
		}

		foreach ($items as $item) {
			$currentIds[spl_object_id($item)] = true;
			if (! $relation->contains($item)) {
				$relation->add($item);
			}
		}

		foreach ($relation->getItems() as $known) {
			$id = spl_object_id($known);
			if (! array_key_exists($id, $currentIds) && ! array_key_exists($id, $addedIds)) {
				$relation->remove($known);
			}
		}
	}
}
