<?php

declare(strict_types=1);

namespace ON\Data\ORM\Sync;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Key;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RecordStateStore;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationFieldBinding;

final class AdoptionRecordResolver
{
	private RepresentationReader $reader;

	public function __construct(?RepresentationReader $reader = null)
	{
		$this->reader = $reader ?? new RepresentationReader();
	}

	public function resolve(
		object $representation,
		RepresentationBinding $binding,
		RecordStateStore $records,
		bool $isRoot,
		?array $sourceRow = null,
		?CollectionInterface $rootCollection = null,
	): RecordState {
		$collection = $this->collectionFor($binding, $isRoot, $rootCollection);
		$values = $this->initialValues($representation, $binding, $collection, $sourceRow);
		$keyValues = $this->completeKeyValues($representation, $binding, $collection, $sourceRow);

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
	 * @return array<string, RecordState>
	 */
	public function resolveAll(
		object $representation,
		RepresentationBinding $binding,
		RecordStateStore $records,
		CollectionInterface $rootCollection,
		?array $sourceRow = null,
	): array {
		$resolved = [];
		$groupedBindings = $this->groupFieldBindingsByCollection($binding);

		foreach ($groupedBindings as $collectionName => $fieldBindings) {
			$collection = $fieldBindings[0]->getField()->getCollection();
			$collectionBinding = $this->bindingForFields($binding, $fieldBindings);
			$isRoot = $collectionName === $rootCollection->getName();
			$resolved[$collectionName] = $this->resolve(
				$representation,
				$collectionBinding,
				$records,
				$isRoot,
				$sourceRow,
				$isRoot ? $rootCollection : null,
			);
		}

		if (! isset($resolved[$rootCollection->getName()])) {
			$resolved[$rootCollection->getName()] = $this->resolve(
				$representation,
				$this->bindingForCollection($binding, $rootCollection),
				$records,
				true,
				$sourceRow,
				$rootCollection,
			);
		}

		return $resolved;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function initialValuesForKey(
		object $representation,
		RepresentationBinding $binding,
		Key $key,
	): array {
		$values = $key->getValues();
		foreach ($binding->getFields() as $fieldBinding) {
			$fieldName = $fieldBinding->getField()->getFieldName();

			try {
				$values[$fieldName] = $this->reader->readPath($representation, $fieldBinding->getPath());
			} catch (SyncException) {
			}
		}

		return $values;
	}

	private function collectionFor(
		RepresentationBinding $binding,
		bool $isRoot,
		?CollectionInterface $rootCollection = null,
	): CollectionInterface {
		if ($isRoot && $rootCollection instanceof CollectionInterface) {
			return $rootCollection;
		}

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

	/**
	 * @return non-empty-array<string, string|int|float|bool>|null
	 */
	private function completeKeyValues(
		object $representation,
		RepresentationBinding $binding,
		CollectionInterface $collection,
		?array $sourceRow = null,
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
				$value = $this->readPath($representation, $pathsByField[$fieldName], $sourceRow);
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
	private function initialValues(
		object $representation,
		RepresentationBinding $binding,
		CollectionInterface $collection,
		?array $sourceRow = null,
	): array {
		$values = [];
		$primaryKey = array_flip($collection->getPrimaryKey());
		foreach ($binding->getFields() as $fieldBinding) {
			$field = $fieldBinding->getField();

			if ($field->getCollectionName() !== $collection->getName()) {
				continue;
			}

			$fieldName = $field->getFieldName();

			try {
				$value = $this->readPath($representation, $fieldBinding->getPath(), $sourceRow);
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

	private function readPath(object $representation, string $path, ?array $sourceRow = null): mixed
	{
		try {
			return $this->reader->readPath($representation, $path);
		} catch (SyncException $exception) {
			if ($sourceRow !== null && array_key_exists($path, $sourceRow)) {
				return $sourceRow[$path];
			}

			throw $exception;
		}
	}

	/**
	 * @return array<string, list<RepresentationFieldBinding>>
	 */
	private function groupFieldBindingsByCollection(RepresentationBinding $binding): array
	{
		$grouped = [];

		foreach ($binding->getFields() as $fieldBinding) {
			$collectionName = $fieldBinding->getField()->getCollectionName();
			$grouped[$collectionName] ??= [];
			$grouped[$collectionName][] = $fieldBinding;
		}

		return $grouped;
	}

	/**
	 * @param list<RepresentationFieldBinding> $fieldBindings
	 */
	private function bindingForFields(RepresentationBinding $binding, array $fieldBindings): RepresentationBinding
	{
		$collectionBinding = new RepresentationBinding();

		foreach ($fieldBindings as $fieldBinding) {
			$collectionBinding->addField($fieldBinding);
		}

		foreach ($binding->getRelations() as $relationBinding) {
			$collectionBinding->addRelation($relationBinding);
		}

		return $collectionBinding;
	}

	private function bindingForCollection(
		RepresentationBinding $binding,
		CollectionInterface $collection,
	): RepresentationBinding {
		$collectionBinding = new RepresentationBinding();

		foreach ($binding->getFields() as $fieldBinding) {
			if ($fieldBinding->getField()->getCollectionName() !== $collection->getName()) {
				continue;
			}

			$collectionBinding->addField($fieldBinding);
		}

		foreach ($binding->getRelations() as $relationBinding) {
			if ($relationBinding->getRelation()->getCollectionName() !== $collection->getName()) {
				continue;
			}

			$collectionBinding->addRelation($relationBinding);
		}

		return $collectionBinding;
	}
}
