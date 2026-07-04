<?php

declare(strict_types=1);

namespace ON\Data\ORM\Sync;

use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\Relation\RelatedCollection;
use ON\Data\ORM\Relation\RelatedCollectionMap;
use ON\Data\ORM\State\RepresentationRelationBinding;
use ON\Data\ORM\State\TrackedRepresentationMap;

final class RelationGraphSynchronizer
{
	private RepresentationValueReader $reader;

	public function __construct(?RepresentationValueReader $reader = null)
	{
		$this->reader = $reader ?? new RepresentationValueReader();
	}

	/**
	 * @return list<RelatedCollection>
	 */
	public function sync(
		TrackedRepresentationMap $representations,
		RelatedCollectionMap $relations,
	): array {
		$touched = [];
		$touchedIds = [];

		foreach ($representations->getAll() as $tracked) {
			$representation = $tracked->getRepresentation();
			foreach ($tracked->getBinding()->getRelations() as $relationBinding) {
				if (! $relationBinding->isMany()) {
					continue;
				}

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

				$id = spl_object_id($collection);
				if (! array_key_exists($id, $touchedIds)) {
					$touchedIds[$id] = true;
					$touched[] = $collection;
				}
			}
		}

		return $touched;
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
