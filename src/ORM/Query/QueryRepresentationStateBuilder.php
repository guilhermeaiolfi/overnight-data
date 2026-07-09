<?php

declare(strict_types=1);

namespace ON\Data\ORM\Query;

/**
 * Converts flat mutable query projection input into RepresentationState.
 *
 * Exists because flat stdClass projections can span collections via hidden
 * identity selections; Session graph adoption handles nested object graphs instead.
 */
use ON\Data\ORM\Compiler\ProjectionSource;
use ON\Data\ORM\Compiler\SelectQuery\ProjectionCompilation;
use ON\Data\ORM\Compiler\SelectQuery\ProjectionIdentityColumns;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RecordStateStore;
use ON\Data\ORM\State\RepresentationState;
use ON\Data\ORM\Sync\RepresentationReader;

final class QueryRepresentationStateBuilder
{
	public function __construct(
		private ?RepresentationReader $reader = null,
	) {
		$this->reader ??= new RepresentationReader();
	}

	/**
	 * @param array<string, mixed> $sourceRow
	 */
	public function build(
		object $representation,
		ProjectionCompilation $compilation,
		array $sourceRow,
		RecordStateStore $records,
	): RepresentationState {
		$schema = $compilation->getSchema();

		if ($schema->getRelations() !== []) {
			throw new StateException('Cannot build flat projection representation because the schema contains relation schemas.');
		}

		$recordsBySourceKey = $this->resolveSourceRecords(
			$representation,
			$compilation->getSources(),
			$compilation->getIdentityColumns(),
			$records,
			$sourceRow,
		);

		return RepresentationState::fromRecords($schema, $recordsBySourceKey);
	}

	/**
	 * @param list<ProjectionSource> $sources
	 *
	 * @return array<string, RecordState>
	 */
	private function resolveSourceRecords(
		object $representation,
		array $sources,
		ProjectionIdentityColumns $identityColumns,
		RecordStateStore $records,
		array $sourceRow,
	): array {
		$resolved = [];

		foreach ($sources as $source) {
			$resolved[$source->getPathKey()] = $this->resolveSourceRecord(
				$representation,
				$source,
				$identityColumns,
				$records,
				$sourceRow,
			);
		}

		return $resolved;
	}

	private function resolveSourceRecord(
		object $representation,
		ProjectionSource $source,
		ProjectionIdentityColumns $identityColumns,
		RecordStateStore $records,
		array $sourceRow,
	): RecordState {
		$collection = $source->getCollection();
		$values = $this->initialValues($representation, $source, $sourceRow);
		$keyValues = $this->completeKeyValues($representation, $source, $identityColumns, $sourceRow);
		$key = $collection->getKey($keyValues);
		$record = $records->getByKey($key);

		if ($record instanceof RecordState) {
			if ($record->isRemoved()) {
				throw new StateException(sprintf(
					"Cannot build projection representation for collection '%s' because key '%s' is already tracked as removed.",
					$collection->getName(),
					$key->getDebugString()
				));
			}

			return $record;
		}

		return RecordState::clean($key, $values);
	}

	/**
	 * @return non-empty-array<string, string|int|float|bool>
	 */
	private function completeKeyValues(
		object $representation,
		ProjectionSource $source,
		ProjectionIdentityColumns $identityColumns,
		array $sourceRow,
	): array {
		$collection = $source->getCollection();
		$values = [];

		foreach ($collection->getPrimaryKey() as $fieldName) {
			$path = $source->getFieldPath($fieldName);
			$value = $path !== null
				? $this->readValue($representation, $path, $sourceRow)
				: null;

			if ($value === null) {
				$resultKey = $identityColumns->get($source->getPath(), $fieldName);

				if ($resultKey === null) {
					throw new StateException(sprintf(
						"Cannot build projection representation for collection '%s' because primary key field '%s' is missing or incomplete.",
						$collection->getName(),
						$fieldName,
					));
				}

				if (! array_key_exists($resultKey, $sourceRow)) {
					throw new StateException(sprintf(
						"Cannot build projection representation for collection '%s' because internal result key '%s' for primary key field '%s' is missing from the source row.",
						$collection->getName(),
						$resultKey,
						$fieldName,
					));
				}

				$value = $sourceRow[$resultKey];
			}

			if ($value === null) {
				throw new StateException(sprintf(
					"Cannot build projection representation for collection '%s' because primary key field '%s' is missing or incomplete.",
					$collection->getName(),
					$fieldName,
				));
			}

			$values[$fieldName] = $value;
		}

		/** @var non-empty-array<string, string|int|float|bool> $values */
		return $values;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function initialValues(
		object $representation,
		ProjectionSource $source,
		array $sourceRow,
	): array {
		$collection = $source->getCollection();
		$values = [];
		$primaryKey = array_flip($collection->getPrimaryKey());

		foreach ($source->getFields() as $fieldSchema) {
			$fieldName = $fieldSchema->getFieldName();
			$value = $this->readValue($representation, $fieldSchema->getPath(), $sourceRow);

			if ($value === null && array_key_exists($fieldName, $primaryKey)) {
				continue;
			}

			if ($value === null) {
				continue;
			}

			$values[$fieldName] = $value;
		}

		return $values;
	}

	private function readValue(object $representation, string $path, array $sourceRow): mixed
	{
		try {
			return $this->reader->readPath($representation, $path);
		} catch (SyncException) {
			if (array_key_exists($path, $sourceRow)) {
				return $sourceRow[$path];
			}

			return null;
		}
	}
}
