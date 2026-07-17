<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Sync;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Key;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\Record\RecordState;
use ON\Data\ORM\Record\RecordStateStore;
use ON\Data\ORM\Representation\Schema\RepresentationSchema;

/**
 * Resolves RecordState for graph adoption.
 *
 * update intent = PATCH existing row (key-only clean baseline + present DTO fields).
 * create / unmarked = NEW record.
 * identify() stays separate (key-only, no field writes).
 */
final class AdoptionRecordResolver
{
	private RepresentationReader $reader;
	private ?RepresentationIntentStore $intents;

	public function __construct(
		?RepresentationReader $reader = null,
		?RepresentationIntentStore $intents = null,
	) {
		$this->reader = $reader ?? new RepresentationReader();
		$this->intents = $intents;
	}

	public function resolve(
		object $representation,
		RepresentationSchema $schema,
		RecordStateStore $records,
		bool $isRoot,
	): RecordState {
		$collection = $schema->requireHomogeneousCollection($isRoot);
		$values = $this->initialValues($representation, $schema, $collection);
		$keyValues = $this->completeKeyValues($representation, $schema, $collection, $isRoot);

		if ($keyValues === null) {
			if ($this->hasUpdateIntent($representation)) {
				throw new StateException(sprintf(
					"Cannot adopt update representation for collection '%s' because its primary key cannot be read through the schema or intent identity.",
					$collection->getName()
				));
			}

			return RecordState::new($collection, $values);
		}

		$key = $collection->getKey($keyValues);
		$removedMessage = sprintf(
			"Cannot adopt representation for collection '%s' because key '%s' is already tracked as removed.",
			$collection->getName(),
			$key->getDebugString()
		);

		if ($this->hasUpdateIntent($representation)) {
			return $records->bindExisting($key, $values, $removedMessage);
		}

		$existing = $records->getActive($key, $removedMessage);
		if ($existing instanceof RecordState) {
			return $existing;
		}

		return RecordState::new($collection, $values);
	}

	/**
	 * Adopt an existing row as a clean snapshot of present schema values (query hydrate).
	 * Unlike update-intent resolve, this does not PATCH / dirty present fields.
	 */
	public function resolveClean(
		object $representation,
		RepresentationSchema $schema,
		RecordStateStore $records,
		bool $isRoot = true,
	): RecordState {
		$collection = $schema->requireHomogeneousCollection($isRoot);
		$keyValues = $this->completeKeyValues($representation, $schema, $collection, $isRoot);
		if ($keyValues === null) {
			throw new StateException(sprintf(
				"Cannot adopt clean representation for collection '%s' because its primary key cannot be read through the schema.",
				$collection->getName(),
			));
		}

		$key = $collection->getKey($keyValues);
		$existing = $records->getActive(
			$key,
			sprintf(
				"Cannot adopt representation for collection '%s' because key '%s' is already tracked as removed.",
				$collection->getName(),
				$key->getDebugString()
			),
		);
		if ($existing instanceof RecordState) {
			return $existing;
		}

		$record = RecordState::clean($key, $this->initialValuesForKey($representation, $schema, $key));
		$records->add($record);

		return $record;
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

	/**
	 * @return non-empty-array<string, string|int|float|bool>|null
	 */
	private function completeKeyValues(
		object $representation,
		RepresentationSchema $schema,
		CollectionInterface $collection,
		bool $isRoot,
	): ?array {
		if ($isRoot) {
			$fromIdentity = $this->keyValuesFromIntentIdentity($representation, $collection);
			if ($fromIdentity !== null) {
				$key = $collection->getKey($fromIdentity);
				$conflict = $key->conflictingIdentityField(
					$this->readablePrimaryKeyValues($representation, $schema, $collection),
				);
				if ($conflict !== null) {
					throw new StateException(sprintf(
						"Cannot adopt update representation for collection '%s' because intent identity field '%s' disagrees with the representation.",
						$collection->getName(),
						$conflict,
					));
				}

				return $fromIdentity;
			}
		}

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

	/**
	 * @return non-empty-array<string, string|int|float|bool>|null
	 */
	private function keyValuesFromIntentIdentity(
		object $representation,
		CollectionInterface $collection,
	): ?array {
		$identity = $this->intents?->get($representation)?->getIdentity();
		if ($identity === null) {
			return null;
		}

		return $collection->getKey($identity)->getValues();
	}

	/**
	 * @return array<string, mixed>
	 */
	private function readablePrimaryKeyValues(
		object $representation,
		RepresentationSchema $schema,
		CollectionInterface $collection,
	): array {
		$pathsByField = [];
		foreach ($schema->getFields() as $fieldSchema) {
			if ($fieldSchema->getCollectionName() === $collection->getName()) {
				$pathsByField[$fieldSchema->getFieldName()] = $fieldSchema->getPath();
			}
		}

		$values = [];
		foreach ($collection->getPrimaryKey() as $fieldName) {
			if (! array_key_exists($fieldName, $pathsByField)) {
				continue;
			}

			try {
				$values[$fieldName] = $this->reader->readPath($representation, $pathsByField[$fieldName]);
			} catch (SyncException) {
			}
		}

		return $values;
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

	private function hasUpdateIntent(object $representation): bool
	{
		return $this->intents instanceof RepresentationIntentStore
			&& $this->intents->isUpdate($representation);
	}
}
