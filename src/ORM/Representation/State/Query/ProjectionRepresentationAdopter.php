<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\State\Query;
use ON\Data\ORM\Record\RecordState;
use ON\Data\ORM\Record\RecordStateStore;
use ON\Data\ORM\Representation\State\RepresentationState;
use ON\Data\ORM\Representation\Sync\RepresentationReader;
use ON\Data\ORM\Representation\Schema\Shape\RepresentationSource;
use ON\Data\ORM\Representation\Schema\Query\QueryRepresentationPlan;
use ON\Data\ORM\Representation\Schema\Query\QueryRepresentationIdentityColumns;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\SessionContext;
/**
 * Adopts flat mutable query projections into tracked RepresentationState by
 * resolving multiple RecordState identities from one result row.
 *
 * Exists because flat stdClass projections can span collections via hidden
 * identity selections; GraphAdopter handles nested object graphs instead.
 */
final class ProjectionRepresentationAdopter
{
	public function __construct(
		private ?RepresentationReader $reader = null,
	) {
		$this->reader ??= new RepresentationReader();
	}

	/**
	 * @param array<string, mixed> $sourceRow
	 */
	public function adopt(
		object $representation,
		QueryRepresentationPlan $compilation,
		array $sourceRow,
		SessionContext $context,
	): RepresentationState {
		$schema = $compilation->getSchema();

		if ($schema->getRelations() !== []) {
			throw new StateException('Cannot adopt flat projection representation because the schema contains relation schemas.');
		}

		$records = $context->getRecords();

		$recordsBySourceKey = $this->resolveSourceRecords(
			$representation,
			$compilation->getSources(),
			$compilation->getIdentityColumns(),
			$records,
			$sourceRow,
		);

		$representations = $context->getRepresentations();

		if ($representations->has($representation)) {
			throw new SyncException('Cannot adopt representation because it is already tracked.');
		}

		$state = RepresentationState::fromRecords($schema, $recordsBySourceKey);

		foreach ($this->uniqueRecords($recordsBySourceKey) as $record) {
			$records->add($record);
		}

		$representations->add($representation, $state);

		return $state;
	}

	/**
	 * @param list<RepresentationSource> $sources
	 *
	 * @return array<string, RecordState>
	 */
	private function resolveSourceRecords(
		object $representation,
		array $sources,
		QueryRepresentationIdentityColumns $identityColumns,
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
		RepresentationSource $source,
		QueryRepresentationIdentityColumns $identityColumns,
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
					"Cannot adopt projection representation for collection '%s' because key '%s' is already tracked as removed.",
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
		RepresentationSource $source,
		QueryRepresentationIdentityColumns $identityColumns,
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
						"Cannot adopt projection representation for collection '%s' because primary key field '%s' is missing or incomplete.",
						$collection->getName(),
						$fieldName,
					));
				}

				if (! array_key_exists($resultKey, $sourceRow)) {
					throw new StateException(sprintf(
						"Cannot adopt projection representation for collection '%s' because internal result key '%s' for primary key field '%s' is missing from the source row.",
						$collection->getName(),
						$resultKey,
						$fieldName,
					));
				}

				$value = $sourceRow[$resultKey];
			}

			if ($value === null) {
				throw new StateException(sprintf(
					"Cannot adopt projection representation for collection '%s' because primary key field '%s' is missing or incomplete.",
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
		RepresentationSource $source,
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

	/**
	 * @param array<string, RecordState> $recordsBySourceKey
	 * @return list<RecordState>
	 */
	private function uniqueRecords(array $recordsBySourceKey): array
	{
		$records = [];
		foreach ($recordsBySourceKey as $record) {
			$records[spl_object_id($record)] = $record;
		}

		return array_values($records);
	}
}
