<?php

declare(strict_types=1);

namespace ON\Data\ORM\Sync;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RecordStateStore;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationState;
use ON\Data\ORM\State\RepresentationStore;

final class GraphAdopter
{
	private RepresentationReader $reader;

	public function __construct(?RepresentationReader $reader = null)
	{
		$this->reader = $reader ?? new RepresentationReader();
	}

	/**
	 * @return list<RepresentationState>
	 */
	public function adopt(
		object $root,
		RepresentationStore $representations,
		RecordStateStore $records,
		?RepresentationBinding $rootBinding = null,
	): array {
		if ($representations->get($root) === null) {
			if (! $rootBinding instanceof RepresentationBinding) {
				throw new StateException('Cannot adopt representation graph because the root representation is not tracked and no root binding was provided.');
			}

			(new RepresentationAdopter($records, $representations))->adopt(
				$root,
				$rootBinding,
				$this->resolveRecordForAdoption($root, $rootBinding, $records, true)
			);
		}

		$adopter = new RepresentationAdopter($records, $representations);
		$visited = [];
		$adopted = [];

		$this->walk($root, $representations, $records, $adopter, $visited, $adopted);

		return $adopted;
	}

	/**
	 * @param array<int, true> $visited
	 * @param list<RepresentationState> $adopted
	 */
	private function walk(
		object $representation,
		RepresentationStore $representations,
		RecordStateStore $records,
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
				foreach ($this->reader->readItems($representation, $relationBinding, $this->adoptionError(...)) as $item) {
					$this->adoptAndWalk($item, $relationBinding->getRelatedBinding(), $representations, $records, $adopter, $visited, $adopted);
				}

				continue;
			}

			if ($relationBinding->isSingle()) {
				$target = $this->reader->readTarget($representation, $relationBinding, $this->adoptionError(...));
				if ($target !== null) {
					$this->adoptAndWalk($target, $relationBinding->getRelatedBinding(), $representations, $records, $adopter, $visited, $adopted);
				}
			}
		}
	}

	/**
	 * @param array<int, true> $visited
	 * @param list<RepresentationState> $adopted
	 */
	private function adoptAndWalk(
		object $representation,
		RepresentationBinding $binding,
		RepresentationStore $representations,
		RecordStateStore $records,
		RepresentationAdopter $adopter,
		array &$visited,
		array &$adopted,
	): void {
		if (! $representations->has($representation)) {
			$adopted[] = $adopter->adopt(
				$representation,
				$binding,
				$this->resolveRecordForAdoption($representation, $binding, $records, false)
			);
		}

		$this->walk($representation, $representations, $records, $adopter, $visited, $adopted);
	}

	/**
	 * @param non-empty-string $message
	 */
	private function adoptionError(string $message): StateException
	{
		return new StateException(rtrim($message, '.') . ' during graph adoption.');
	}

	private function collectionFor(RepresentationBinding $binding, bool $isRoot): CollectionInterface
	{
		$collection = null;
		foreach ($binding->getFields() as $fieldBinding) {
			$field = $fieldBinding->getField();
			$collection = $this->mergeCollection($collection, $field->getCollection(), $fieldBinding->getPath(), $isRoot);
		}

		foreach ($binding->getRelations() as $relationBinding) {
			$relation = $relationBinding->getRelation();
			$collection = $this->mergeCollection($collection, $relation->getCollection(), $relationBinding->getPath(), $isRoot);
		}

		if (! $collection instanceof CollectionInterface) {
			if ($isRoot) {
				throw new StateException('Cannot synchronize untracked root representation because untracked root sync needs a binding targeting one collection.');
			}

			throw new StateException('Cannot adopt representation graph because a related binding does not target a collection.');
		}

		return $collection;
	}

	private function resolveRecordForAdoption(
		object $representation,
		RepresentationBinding $binding,
		RecordStateStore $records,
		bool $isRoot,
	): RecordState {
		$collection = $this->collectionFor($binding, $isRoot);
		$values = $this->initialValues($representation, $binding, $collection);
		$keyValues = $this->completeKeyValues($representation, $binding, $collection);

		if ($keyValues === null) {
			return RecordState::new($collection, $values);
		}

		$key = $collection->getKey($keyValues);
		$record = $records->getByKey($key);
		if ($record instanceof RecordState) {
			if ($record->isRemoved()) {
				throw new StateException(sprintf(
					"Cannot adopt representation for collection '%s' because key '%s' is already tracked as removed.",
					$collection->getName(),
					$key->getDebugString()
				));
			}

			return $record;
		}

		return RecordState::clean($key, $values);
	}

	/**
	 * @return non-empty-array<string, string|int|float|bool>|null
	 */
	private function completeKeyValues(
		object $representation,
		RepresentationBinding $binding,
		CollectionInterface $collection,
	): ?array {
		$pathsByField = [];
		foreach ($binding->getFields() as $fieldBinding) {
			$field = $fieldBinding->getField();
			if ($field->getCollectionName() === $collection->getName()) {
				$pathsByField[$field->getFieldName()] = $fieldBinding->getPath();
			}
		}

		$values = [];
		foreach ($collection->getPrimaryKey() as $fieldName) {
			if (! array_key_exists($fieldName, $pathsByField)) {
				return null;
			}

			try {
				$value = $this->reader->readPath($representation, $pathsByField[$fieldName]);
			} catch (SyncException) {
				return null;
			}

			if ($value === null) {
				return null;
			}

			$values[$fieldName] = $value;
		}

		/** @var non-empty-array<string, string|int|float|bool> $values */
		return $values;
	}

	private function mergeCollection(?CollectionInterface $current, CollectionInterface $next, string $path, bool $isRoot): CollectionInterface
	{
		if ($current === null || $current === $next) {
			return $next;
		}

		if ($current->getName() !== $next->getName()) {
			if ($isRoot) {
				throw new StateException(sprintf(
					"Cannot synchronize untracked root representation because untracked root sync needs a binding targeting one collection; path '%s' targets collection '%s' after '%s'.",
					$path,
					$next->getName(),
					$current->getName()
				));
			}

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
	private function initialValues(object $representation, RepresentationBinding $binding, CollectionInterface $collection): array
	{
		$values = [];
		$primaryKey = array_flip($collection->getPrimaryKey());
		foreach ($binding->getFields() as $fieldBinding) {
			$fieldName = $fieldBinding->getField()->getFieldName();

			try {
				$value = $this->reader->readPath($representation, $fieldBinding->getPath());
			} catch (SyncException) {
				continue;
			}

			if ($value === null && array_key_exists($fieldName, $primaryKey)) {
				continue;
			}

			$values[$fieldName] = $value;
		}

		return $values;
	}
}
