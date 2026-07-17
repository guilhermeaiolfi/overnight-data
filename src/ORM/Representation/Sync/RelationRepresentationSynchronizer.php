<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Sync;

use ON\Data\Definition\Relation\RelationCardinality;
use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\Relation\RelationStateInterface;
use ON\Data\ORM\Relation\RelationStateStore;
use ON\Data\ORM\Representation\State\RepresentationRelationStateItem;
use ON\Data\ORM\Representation\State\RepresentationState;
use ON\Data\ORM\Representation\State\RepresentationStateStore;

final class RelationRepresentationSynchronizer
{
	private RepresentationReader $reader;

	public function __construct(?RepresentationReader $reader = null)
	{
		$this->reader = $reader ?? new RepresentationReader();
	}

	/**
	 * @return list<RelationStateInterface>
	 */
	public function sync(
		RepresentationStateStore $reps,
		RelationStateStore $relations,
		?RepresentationStateStore $states = null,
	): array {
		$touched = [];
		$touchedIds = [];
		$states ??= $reps;

		foreach ($reps->getAll() as $representation => $state) {
			foreach ($state->getRelationItems() as $relationItem) {
				$relationSchema = $relationItem->getSchema();
				if ($relationSchema->isMany()) {
					$this->syncMany($representation, $relationItem, $relations, $states, $touched, $touchedIds);

					continue;
				}

				if ($relationSchema->isSingle()) {
					$this->syncOne($representation, $relationItem, $relations, $states, $touched, $touchedIds);
				}
			}
		}

		return $touched;
	}

	/**
	 * @param list<RelationStateInterface> $touched
	 * @param array<int, true> $touchedIds
	 */
	private function syncMany(
		object $representation,
		RepresentationRelationStateItem $relationItem,
		RelationStateStore $relations,
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

		$relation = $relations->getOrCreate(
			$owner,
			$relationName,
			RelationCardinality::MANY,
			$relationSchema->getRelatedSchema(),
		);
		$relation->syncFromItems($items);
		$this->touch($relation, $touched, $touchedIds);
	}

	/**
	 * @param list<RelationStateInterface> $touched
	 * @param array<int, true> $touchedIds
	 */
	private function syncOne(
		object $representation,
		RepresentationRelationStateItem $relationItem,
		RelationStateStore $relations,
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

		$relation = $relations->getOrCreate(
			$owner,
			$relationName,
			RelationCardinality::SINGLE,
			$relationSchema->getRelatedSchema(),
		);
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
	 * @param list<RelationStateInterface> $touched
	 * @param array<int, true> $touchedIds
	 */
	private function touch(RelationStateInterface $change, array &$touched, array &$touchedIds): void
	{
		$id = spl_object_id($change);
		if (! array_key_exists($id, $touchedIds)) {
			$touchedIds[$id] = true;
			$touched[] = $change;
		}
	}
}
