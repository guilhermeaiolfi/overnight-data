<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\State;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Record\RecordState;
use ON\Data\Schema\RepresentationFieldSchema;
use ON\Data\Schema\RepresentationSchema;

final class RepresentationState
{
	/**
	 * @param array<string, RecordState> $recordsBySourceKey
	 * @param array<string, mixed> $baselines
	 */
	private function __construct(
		private RepresentationSchema $schema,
		private array $recordsBySourceKey,
		private array $baselines,
	) {
	}

	/**
	 * @param array<string, RecordState> $recordsBySourceKey
	 * @param array<string, mixed>|null $loadedBaselines Values observed on a newly hydrated representation.
	 */
	public static function fromRecords(
		RepresentationSchema $schema,
		array $recordsBySourceKey,
		?array $loadedBaselines = null,
	): self {
		$baselines = [];
		foreach ($schema->getFields() as $field) {
			$sourceKey = $field->getSourcePathKey();
			$record = $recordsBySourceKey[$sourceKey] ?? null;
			if ($record === null) {
				if ($field->shouldSkipWhenMissing()) {
					continue;
				}

				throw new StateException(sprintf(
					"Cannot create representation state because source path '%s' is unresolved.",
					$sourceKey
				));
			}

			$collectionName = $record->getCollection()->getName();
			if ($field->getCollectionName() !== $collectionName) {
				throw new StateException(sprintf(
					"Representation schema path '%s' targets collection '%s', not '%s'.",
					$field->getPath(),
					$field->getCollectionName(),
					$collectionName
				));
			}

			if ($loadedBaselines !== null && array_key_exists($field->getPath(), $loadedBaselines)) {
				$baselines[$field->getPath()] = $loadedBaselines[$field->getPath()];
			} elseif ($loadedBaselines === null && $record->hasValue($field->getFieldName())) {
				$baselines[$field->getPath()] = $record->getValue($field->getFieldName());
			}
		}

		foreach ($schema->getRelations() as $relation) {
			$root = $recordsBySourceKey[''] ?? throw new StateException(
				"Cannot create representation state because root source path '' is unresolved."
			);
			$collectionName = $root->getCollection()->getName();
			if ($relation->getOwnerCollectionName() !== $collectionName) {
				throw new StateException(sprintf(
					"Representation relation path '%s' targets collection '%s', not '%s'.",
					$relation->getPath(),
					$relation->getOwnerCollectionName(),
					$collectionName
				));
			}
		}

		return new self($schema, $recordsBySourceKey, $baselines);
	}

	public function getSchema(): RepresentationSchema
	{
		return $this->schema;
	}

	public function hasRecordForField(RepresentationFieldSchema $field): bool
	{
		return isset($this->recordsBySourceKey[$field->getSourcePathKey()]);
	}

	public function getRecordForField(RepresentationFieldSchema $field): RecordState
	{
		return $this->recordsBySourceKey[$field->getSourcePathKey()]
			?? throw new StateException(sprintf("Representation state has no record for field path '%s'.", $field->getPath()));
	}

	/** @return list<RepresentationFieldSchema> */
	public function getWritableFields(): array
	{
		$fields = [];
		foreach ($this->schema->getFields() as $field) {
			if ($field->isWritable() && $this->hasRecordForField($field)) {
				$fields[] = $field;
			}
		}

		return $fields;
	}

	public function getRootRecord(): ?RecordState
	{
		return $this->recordsBySourceKey[''] ?? null;
	}

	public function requireRootRecord(): RecordState
	{
		return $this->getRootRecord() ?? throw new StateException(sprintf(
			"Representation state has no root record for collection '%s'.",
			$this->schema->getCollectionName()
		));
	}

	/** @return list<RecordState> */
	public function getUniqueRecords(): array
	{
		$records = [];
		foreach ($this->recordsBySourceKey as $record) {
			$records[$record->getStateHash()] = $record;
		}

		return array_values($records);
	}

	/** @return list<RecordState> */
	public function getRecordsForCollection(CollectionInterface $collection): array
	{
		return array_values(array_filter(
			$this->getUniqueRecords(),
			static fn (RecordState $record): bool => $record->getCollection()->getName() === $collection->getName(),
		));
	}

	public function getSingleRecord(): ?RecordState
	{
		$records = $this->getUniqueRecords();
		if (count($records) > 1) {
			throw new StateException('Representation state cannot be collapsed to one record.');
		}

		return $records[0] ?? null;
	}

	public function requireSingleRecord(): RecordState
	{
		$records = $this->getUniqueRecords();
		if ($records === []) {
			throw new StateException('Cannot remove representation because its schema does not resolve to a concrete record state.');
		}

		if (count($records) > 1) {
			throw new StateException('Cannot remove representation because its schema resolves to multiple record states.');
		}

		return $records[0];
	}

	public function getOwnerRecord(string $relation): RecordState
	{
		$root = $this->getRootRecord();
		if ($root !== null && ($this->schema->hasRelation($relation) || $root->getCollection()->hasRelation($relation))) {
			return $root;
		}

		$matches = $this->getRecordsForCollection($this->schema->getCollection());
		if (count($matches) === 1) {
			return $matches[0];
		}

		throw new StateException(sprintf(
			"Cannot get owner record for relation '%s' on the tracked representation.",
			$relation,
		));
	}

	public function hasBaseline(string $path): bool
	{
		return array_key_exists($path, $this->baselines);
	}

	public function getBaseline(string $path): mixed
	{
		return $this->baselines[$path] ?? null;
	}

	/** @return array<string, mixed> */
	public function snapshotBaselines(): array
	{
		return $this->baselines;
	}

	/** @param array<string, mixed> $baselines */
	public function restoreBaselines(array $baselines): void
	{
		$this->baselines = $baselines;
	}

	/** @param array<string, mixed> $syncedValuesByPath */
	public function acceptSyncedValues(array $syncedValuesByPath): void
	{
		foreach ($syncedValuesByPath as $path => $value) {
			$this->baselines[$path] = $value;
		}
	}
}
