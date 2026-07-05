<?php

declare(strict_types=1);

namespace ON\Data\ORM\Sync;

use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\Relation\RelatedCollection;
use ON\Data\ORM\Relation\RelatedCollectionMap;
use ON\Data\ORM\Relation\RelatedReference;
use ON\Data\ORM\Relation\RelatedReferenceMap;
use ON\Data\ORM\Relation\RelationChangeInterface;
use ON\Data\ORM\State\RepresentationRelationBinding;
use ON\Data\ORM\State\TrackedRepresentationMap;

final class RelationRepresentationSynchronizer
{
	private RepresentationValueReader $reader;

	public function __construct(?RepresentationValueReader $reader = null)
	{
		$this->reader = $reader ?? new RepresentationValueReader();
	}

	/**
	 * @return list<RelationChangeInterface>
	 */
	public function sync(
		TrackedRepresentationMap $representations,
		RelatedCollectionMap $relations,
		RelatedReferenceMap $references,
		?TrackedRepresentationMap $trackedRepresentations = null,
	): array {
		$touched = [];
		$touchedIds = [];
		$resolver = new TrackedRepresentationResolver($trackedRepresentations ?? $representations);

		foreach ($representations->getAll() as $tracked) {
			$representation = $tracked->getRepresentation();
			foreach ($tracked->getBinding()->getRelations() as $relationBinding) {
				if ($relationBinding->isMany()) {
					$this->syncMany($representation, $relationBinding, $relations, $resolver, $touched, $touchedIds);

					continue;
				}

				if ($relationBinding->isSingle()) {
					$this->syncOne($representation, $relationBinding, $references, $resolver, $touched, $touchedIds);
				}
			}
		}

		return $touched;
	}

	/**
	 * @param list<RelationChangeInterface> $touched
	 * @param array<int, true> $touchedIds
	 */
	private function syncMany(
		object $representation,
		RepresentationRelationBinding $relationBinding,
		RelatedCollectionMap $relations,
		TrackedRepresentationResolver $resolver,
		array &$touched,
		array &$touchedIds,
	): void {
		$relationRef = $relationBinding->getRelation();
		if ($relationRef->isTemplate()) {
			throw new SyncException(sprintf(
				"Representation relation path '%s' must target a concrete record before graph synchronization.",
				$relationBinding->getPath()
			));
		}

		$owner = $relationRef->getState();
		$relationName = $relationRef->getRelationName();
		$items = $this->readItems($representation, $relationBinding);
		foreach ($items as $item) {
			$resolver->getTrackedRepresentation($item, $relationBinding->getPath());
		}

		$collection = $relations->get($owner, $relationName);
		if (! $collection instanceof RelatedCollection) {
			$collection = new RelatedCollection(
				$owner,
				$relationName,
				$relationBinding->getRelatedBinding(),
				$relationBinding->getCollectionState()
			);
			$relations->add($collection);
		}

		$this->applyItems($collection, $items);
		$this->touch($collection, $touched, $touchedIds);
	}

	/**
	 * @param list<RelationChangeInterface> $touched
	 * @param array<int, true> $touchedIds
	 */
	private function syncOne(
		object $representation,
		RepresentationRelationBinding $relationBinding,
		RelatedReferenceMap $references,
		TrackedRepresentationResolver $resolver,
		array &$touched,
		array &$touchedIds,
	): void {
		$relationRef = $relationBinding->getRelation();
		if ($relationRef->isTemplate()) {
			throw new SyncException(sprintf(
				"Representation relation path '%s' must target a concrete record before graph synchronization.",
				$relationBinding->getPath()
			));
		}

		$owner = $relationRef->getState();
		$relationName = $relationRef->getRelationName();
		$target = $this->readTarget($representation, $relationBinding);
		if ($target !== null) {
			$resolver->getTrackedRepresentation($target, $relationBinding->getPath());
		}

		$reference = $references->get($owner, $relationName);
		if (! $reference instanceof RelatedReference) {
			$reference = new RelatedReference(
				$owner,
				$relationName,
				$relationBinding->getRelatedBinding()
			);
			$references->add($reference);
		}

		$reference->set($target);
		$this->touch($reference, $touched, $touchedIds);
	}

	/**
	 * @return list<object>
	 */
	private function readItems(object $representation, RepresentationRelationBinding $binding): array
	{
		$value = $this->reader->readPath($representation, $binding->getPath());
		if ($value === null) {
			return [];
		}

		if (! is_iterable($value)) {
			throw new SyncException(sprintf(
				"Representation relation path '%s' must contain an iterable value or null.",
				$binding->getPath()
			));
		}

		$items = [];
		foreach ($value as $item) {
			if (! is_object($item)) {
				throw new SyncException(sprintf(
					"Representation relation path '%s' can only contain objects.",
					$binding->getPath()
				));
			}

			$items[] = $item;
		}

		return $items;
	}

	private function readTarget(object $representation, RepresentationRelationBinding $binding): ?object
	{
		$value = $this->reader->readPath($representation, $binding->getPath());
		if ($value === null || is_object($value)) {
			return $value;
		}

		throw new SyncException(sprintf(
			"Representation relation path '%s' must contain an object value or null.",
			$binding->getPath()
		));
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
	private function applyItems(RelatedCollection $collection, array $items): void
	{
		if (! $collection->isFullyLoaded()) {
			foreach ($items as $item) {
				$collection->add($item);
			}

			return;
		}

		$currentIds = [];
		foreach ($items as $item) {
			$currentIds[spl_object_id($item)] = true;
			if (! $collection->contains($item)) {
				$collection->add($item);
			}
		}

		foreach ($collection->getItems() as $known) {
			if (! array_key_exists(spl_object_id($known), $currentIds)) {
				$collection->remove($known);
			}
		}
	}
}
