<?php

declare(strict_types=1);

namespace ON\Data\ORM\Sync;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Relation\RelatedCollectionMap;
use ON\Data\ORM\Relation\RelatedReferenceMap;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RecordStateMap;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationRelationBinding;
use ON\Data\ORM\State\TrackedRepresentation;
use ON\Data\ORM\State\TrackedRepresentationMap;

final class GraphAdopter
{
	private RepresentationValueReader $reader;

	public function __construct(?RepresentationValueReader $reader = null)
	{
		$this->reader = $reader ?? new RepresentationValueReader();
	}

	/**
	 * @return list<TrackedRepresentation>
	 */
	public function adopt(
		object $root,
		TrackedRepresentationMap $representations,
		RecordStateMap $records,
		RelatedCollectionMap $relations,
		RelatedReferenceMap $references,
		?RepresentationBinding $rootBinding = null,
	): array {
		if ($representations->get($root) === null) {
			if (! $rootBinding instanceof RepresentationBinding) {
				throw new StateException('Cannot adopt representation graph because the root representation is not tracked and no root binding was provided.');
			}

			(new RepresentationAdopter($records, $representations))->adopt(
				$root,
				$rootBinding,
				RecordState::new($this->collectionFor($rootBinding), $this->initialValues($root, $rootBinding))
			);
		}

		$adopter = new RepresentationAdopter($records, $representations);
		$visited = [];
		$adopted = [];

		$this->walk($root, $representations, $adopter, $visited, $adopted);

		return $adopted;
	}

	/**
	 * @param array<int, true> $visited
	 * @param list<TrackedRepresentation> $adopted
	 */
	private function walk(
		object $representation,
		TrackedRepresentationMap $representations,
		RepresentationAdopter $adopter,
		array &$visited,
		array &$adopted,
	): void {
		$id = spl_object_id($representation);
		if (array_key_exists($id, $visited)) {
			return;
		}

		$tracked = $representations->get($representation);
		if ($tracked === null) {
			throw new StateException('Cannot walk representation graph because a representation is not tracked.');
		}

		$visited[$id] = true;
		foreach ($tracked->getBinding()->getRelations() as $relationBinding) {
			if ($relationBinding->isMany()) {
				foreach ($this->readItems($representation, $relationBinding) as $item) {
					$this->adoptAndWalk($item, $relationBinding->getRelatedBinding(), $representations, $adopter, $visited, $adopted);
				}

				continue;
			}

			if ($relationBinding->isSingle()) {
				$target = $this->readTarget($representation, $relationBinding);
				if ($target !== null) {
					$this->adoptAndWalk($target, $relationBinding->getRelatedBinding(), $representations, $adopter, $visited, $adopted);
				}
			}
		}
	}

	/**
	 * @param array<int, true> $visited
	 * @param list<\ON\Data\ORM\State\TrackedRepresentation> $adopted
	 */
	private function adoptAndWalk(
		object $representation,
		RepresentationBinding $binding,
		TrackedRepresentationMap $representations,
		RepresentationAdopter $adopter,
		array &$visited,
		array &$adopted,
	): void {
		if (! $representations->has($representation)) {
			$adopted[] = $adopter->adopt(
				$representation,
				$binding,
				RecordState::new($this->collectionFor($binding), $this->initialValues($representation, $binding))
			);
		}

		$this->walk($representation, $representations, $adopter, $visited, $adopted);
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
			throw new StateException(sprintf(
				"Representation relation path '%s' must contain an iterable value or null during graph adoption.",
				$binding->getPath()
			));
		}

		$items = [];
		foreach ($value as $item) {
			if (! is_object($item)) {
				throw new StateException(sprintf(
					"Representation relation path '%s' can only contain objects during graph adoption.",
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

		throw new StateException(sprintf(
			"Representation relation path '%s' must contain an object value or null during graph adoption.",
			$binding->getPath()
		));
	}

	private function collectionFor(RepresentationBinding $binding): CollectionInterface
	{
		$collection = null;
		foreach ($binding->getFields() as $fieldBinding) {
			$field = $fieldBinding->getField();
			$collection = $this->mergeCollection($collection, $field->getCollection(), $fieldBinding->getPath());
		}

		foreach ($binding->getRelations() as $relationBinding) {
			$relation = $relationBinding->getRelation();
			$collection = $this->mergeCollection($collection, $relation->getCollection(), $relationBinding->getPath());
		}

		if (! $collection instanceof CollectionInterface) {
			throw new StateException('Cannot adopt representation graph because a related binding does not target a collection.');
		}

		return $collection;
	}

	private function mergeCollection(?CollectionInterface $current, CollectionInterface $next, string $path): CollectionInterface
	{
		if ($current === null || $current === $next) {
			return $next;
		}

		if ($current->getName() !== $next->getName()) {
			throw new StateException(sprintf(
				"Cannot adopt representation graph because related binding path '%s' targets collection '%s' after '%s'.",
				$path,
				$next->getName(),
				$current->getName()
			));
		}

		return $current;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function initialValues(object $representation, RepresentationBinding $binding): array
	{
		$values = [];
		foreach ($binding->getFields() as $fieldBinding) {
			$values[$fieldBinding->getField()->getFieldName()] = $this->reader->readPath($representation, $fieldBinding->getPath());
		}

		return $values;
	}
}
