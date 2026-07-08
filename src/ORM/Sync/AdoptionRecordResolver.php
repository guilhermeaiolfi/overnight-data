<?php

declare(strict_types=1);

namespace ON\Data\ORM\Sync;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Key;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RecordStateStore;
use ON\Data\ORM\State\RepresentationSchema;

final class AdoptionRecordResolver
{
	private RepresentationReader $reader;
	private ?ExistingIntentStore $existingIntents;

	public function __construct(
		?RepresentationReader $reader = null,
		?ExistingIntentStore $existingIntents = null,
	) {
		$this->reader = $reader ?? new RepresentationReader();
		$this->existingIntents = $existingIntents;
	}

	public function resolve(
		object $representation,
		RepresentationSchema $schema,
		RecordStateStore $records,
		bool $isRoot,
	): RecordState {
		$collection = $this->collectionFor($schema, $isRoot);
		$values = $this->initialValues($representation, $schema, $collection);
		$keyValues = $this->completeKeyValues($representation, $schema, $collection);

		if ($keyValues === null) {
			if ($this->hasExistingIntent($representation)) {
				throw new StateException(sprintf(
					"Cannot adopt existing representation for collection '%s' because its primary key cannot be read through the schema.",
					$collection->getName()
				));
			}

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

		if ($this->hasExistingIntent($representation) || $isRoot) {
			return RecordState::clean($key, $values);
		}

		return RecordState::new($collection, $values);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function initialValuesForKey(
		object $representation,
		RepresentationSchema $schema,
		Key $key,
	): array {
		$values = $key->getValues();
		foreach ($schema->getFields() as $fieldSchema) {
			$fieldName = $fieldSchema->getFieldName();

			try {
				$values[$fieldName] = $this->reader->readPath($representation, $fieldSchema->getPath());
			} catch (SyncException) {
			}
		}

		return $values;
	}

	private function collectionFor(RepresentationSchema $schema, bool $isRoot): CollectionInterface
	{
		$collection = null;
		foreach ($schema->getFields() as $fieldSchema) {
			$collection = $this->mergeCollection($collection, $fieldSchema->getCollection(), $fieldSchema->getPath(), $isRoot);
		}

		foreach ($schema->getRelations() as $relationSchema) {
			$collection = $this->mergeCollection($collection, $relationSchema->getOwnerCollection(), $relationSchema->getPath(), $isRoot);
		}

		if (! $collection instanceof CollectionInterface) {
			if ($isRoot) {
				throw new StateException('Cannot synchronize untracked root representation because untracked root sync needs a schema targeting one collection.');
			}

			throw new StateException('Cannot adopt representation graph because a related schema does not target a collection.');
		}

		return $collection;
	}

	/**
	 * @return non-empty-array<string, string|int|float|bool>|null
	 */
	private function completeKeyValues(
		object $representation,
		RepresentationSchema $schema,
		CollectionInterface $collection,
	): ?array {
		$pathsByField = [];
		foreach ($schema->getFields() as $fieldSchema) {
			if ($fieldSchema->getCollectionName() === $collection->getName()) {
				$pathsByField[$fieldSchema->getFieldName()] = $fieldSchema->getPath();
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
					"Cannot synchronize untracked root representation because untracked root sync needs a schema targeting one collection; path '%s' targets collection '%s' after '%s'.",
					$path,
					$next->getName(),
					$current->getName()
				));
			}

			throw new StateException(sprintf(
				"Cannot adopt representation graph because related schema path '%s' targets collection '%s' after '%s'.",
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
	private function initialValues(object $representation, RepresentationSchema $schema, CollectionInterface $collection): array
	{
		$values = [];
		$primaryKey = array_flip($collection->getPrimaryKey());
		foreach ($schema->getFields() as $fieldSchema) {
			$fieldName = $fieldSchema->getFieldName();

			try {
				$value = $this->reader->readPath($representation, $fieldSchema->getPath());
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

	private function hasExistingIntent(object $representation): bool
	{
		return $this->existingIntents instanceof ExistingIntentStore
			&& $this->existingIntents->isMarked($representation);
	}
}
