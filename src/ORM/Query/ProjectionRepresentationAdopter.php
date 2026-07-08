<?php

declare(strict_types=1);

namespace ON\Data\ORM\Query;

/**
 * Adopts flat mutable query projections into tracked RepresentationState by
 * resolving multiple RecordState identities from one result row.
 *
 * Exists because flat stdClass projections can span collections via hidden
 * identity selections; GraphAdopter handles nested object graphs instead.
 */
use ON\Data\ORM\Compiler\ProjectionSource;
use ON\Data\ORM\Compiler\SelectQuery\ProjectionCompilation;
use ON\Data\ORM\Compiler\SelectQuery\ProjectionIdentityColumns;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\SessionContext;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RecordStateStore;
use ON\Data\ORM\State\RepresentationState;
use ON\Data\ORM\Sync\RepresentationReader;
use ON\Data\ORM\Sync\RepresentationStateFactory;

final class ProjectionRepresentationAdopter
{
	public function __construct(
		private ?RepresentationReader $reader = null,
		private RepresentationStateFactory $stateFactory = new RepresentationStateFactory(),
	) {
		$this->reader ??= new RepresentationReader();
	}

	/**
	 * @param array<string, mixed> $sourceRow
	 */
	public function adopt(
		object $representation,
		ProjectionCompilation $compilation,
		array $sourceRow,
		SessionContext $context,
	): RepresentationState {
		$schema = $compilation->getSchema();

		if ($schema->getRelations() !== []) {
			throw new StateException('Cannot adopt flat projection representation because the schema contains relation schemas.');
		}

		$records = $context->getRecords();
		$representations = $context->getRepresentations();

		if ($representations->has($representation)) {
			throw new SyncException('Cannot adopt representation because it is already tracked.');
		}

		$recordsBySourceKey = $this->resolveSourceRecords(
			$representation,
			$compilation->getSources(),
			$compilation->getIdentityColumns(),
			$records,
			$sourceRow,
		);
		$state = $this->stateFactory->fromSourceRecords(
			$schema,
			$compilation->getSources(),
			$recordsBySourceKey,
		);

		foreach ($recordsBySourceKey as $record) {
			$records->add($record);
		}

		$representations->add($representation, $state);

		return $state;
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
