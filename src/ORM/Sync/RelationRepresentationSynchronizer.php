<?php

declare(strict_types=1);

namespace ON\Data\ORM\Sync;

use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\Relation\RelationChangeInterface;
use ON\Data\ORM\Relation\RelationStateStore;
use ON\Data\ORM\Relation\ToManyRelationState;
use ON\Data\ORM\Relation\ToOneRelationState;
use ON\Data\ORM\State\RepresentationRelationBinding;
use ON\Data\ORM\State\RepresentationStore;

final class RelationRepresentationSynchronizer
{
	private RepresentationValueReader $reader;

	public function __construct(?RepresentationValueReader $reader = null)
	{
		$this->reader = $reader ?? new RepresentationValueReader();
	}

	/**
	 * @return list<RelationChangeInterface>
	 *
	 * @param RelationStateStore<ToManyRelationState> $toManyRelations
	 * @param RelationStateStore<ToOneRelationState> $toOneRelations
	 */
	public function sync(
		RepresentationStore $representations,
		RelationStateStore $toManyRelations,
		RelationStateStore $toOneRelations,
		?RepresentationStore $states = null,
	): array {
		$touched = [];
		$touchedIds = [];
		$resolver = new RepresentationStateResolver($states ?? $representations);

		foreach ($representations->getAll() as $representation => $state) {
			foreach ($state->getBinding()->getRelations() as $relationBinding) {
				if ($relationBinding->isMany()) {
					$this->syncMany($representation, $relationBinding, $toManyRelations, $resolver, $touched, $touchedIds);

					continue;
				}

				if ($relationBinding->isSingle()) {
					$this->syncOne($representation, $relationBinding, $toOneRelations, $resolver, $touched, $touchedIds);
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
		RepresentationRelationBinding $relationBinding,
		RelationStateStore $toManyRelations,
		RepresentationStateResolver $resolver,
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
			$resolver->getRepresentationState($item, $relationBinding->getPath());
		}

		$relation = $toManyRelations->get($owner, $relationName);
		if (! $relation instanceof ToManyRelationState) {
			$relation = $relationBinding->isCollectionFullyLoaded()
				? ToManyRelationState::full($owner, $relationName, $relationBinding->getRelatedBinding())
				: new ToManyRelationState($owner, $relationName, $relationBinding->getRelatedBinding());
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
		RepresentationRelationBinding $relationBinding,
		RelationStateStore $toOneRelations,
		RepresentationStateResolver $resolver,
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
			$resolver->getRepresentationState($target, $relationBinding->getPath());
		}

		$relation = $toOneRelations->get($owner, $relationName);
		if (! $relation instanceof ToOneRelationState) {
			$relation = new ToOneRelationState(
				$owner,
				$relationName,
				$relationBinding->getRelatedBinding()
			);
			$toOneRelations->add($relation);
		}

		$relation->set($target);
		$this->touch($relation, $touched, $touchedIds);
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
	private function applyItems(ToManyRelationState $relation, array $items): void
	{
		if (! $relation->isFullyLoaded()) {
			foreach ($items as $item) {
				$relation->add($item);
			}

			return;
		}

		$currentIds = [];
		foreach ($items as $item) {
			$currentIds[spl_object_id($item)] = true;
			if (! $relation->contains($item)) {
				$relation->add($item);
			}
		}

		foreach ($relation->getItems() as $known) {
			if (! array_key_exists(spl_object_id($known), $currentIds)) {
				$relation->remove($known);
			}
		}
	}
}
