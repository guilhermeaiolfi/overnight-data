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
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\ORM\Compiler\SelectQuery\ProjectionIdentityColumns;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\SessionContext;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RecordStateStore;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationFieldBinding;
use ON\Data\ORM\State\RepresentationFieldStateItem;
use ON\Data\ORM\State\RepresentationState;
use ON\Data\ORM\Sync\RepresentationReader;

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
		RepresentationBinding $binding,
		ProjectionIdentityColumns $identityColumns,
		array $sourceRow,
		SessionContext $context,
	): RepresentationState {
		if ($binding->getRelations() !== []) {
			throw new StateException('Cannot adopt flat projection representation because the binding contains relation bindings.');
		}

		$records = $context->getRecords();
		$representations = $context->getRepresentations();

		if ($representations->has($representation)) {
			throw new SyncException('Cannot adopt representation because it is already tracked.');
		}

		$recordsBySourcePath = $this->resolveRecordsBySourcePath(
			$representation,
			$binding,
			$identityColumns,
			$records,
			$sourceRow,
		);
		$state = new RepresentationState(
			$binding,
			$this->buildFieldItems($binding, $recordsBySourcePath),
		);

		foreach ($recordsBySourcePath as $record) {
			$records->add($record);
		}

		$representations->add($representation, $state);

		return $state;
	}

	/**
	 * @return array<string, RecordState>
	 */
	private function resolveRecordsBySourcePath(
		object $representation,
		RepresentationBinding $binding,
		ProjectionIdentityColumns $identityColumns,
		RecordStateStore $records,
		array $sourceRow,
	): array {
		$resolved = [];

		foreach ($this->groupFieldBindingsBySourcePath($binding) as $sourceKey => $fieldBindings) {
			$collection = $fieldBindings[0]->getCollection();
			$sourcePath = $fieldBindings[0]->getSourcePath();
			$sourceBinding = $this->bindingForFields($binding, $fieldBindings, $collection);
			$resolved[$sourceKey] = $this->resolveRecord(
				$representation,
				$sourceBinding,
				$collection,
				$sourcePath,
				$identityColumns,
				$records,
				$sourceRow,
			);
		}

		return $resolved;
	}

	/**
	 * @param list<string> $sourcePath
	 */
	private function resolveRecord(
		object $representation,
		RepresentationBinding $binding,
		CollectionInterface $collection,
		array $sourcePath,
		ProjectionIdentityColumns $identityColumns,
		RecordStateStore $records,
		array $sourceRow,
	): RecordState {
		$values = $this->initialValues($representation, $binding, $collection, $sourceRow);
		$keyValues = $this->completeKeyValues($representation, $binding, $collection, $sourcePath, $identityColumns, $sourceRow);
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
	 * @return array<string, list<RepresentationFieldBinding>>
	 */
	private function groupFieldBindingsBySourcePath(RepresentationBinding $binding): array
	{
		/** @var array<string, list<RepresentationFieldBinding>> $grouped */
		$grouped = [];

		foreach ($binding->getFields() as $fieldBinding) {
			$grouped[$fieldBinding->getSourcePathKey()][] = $fieldBinding;
		}

		return $grouped;
	}

	/**
	 * @param list<RepresentationFieldBinding> $fieldBindings
	 */
	private function bindingForFields(RepresentationBinding $binding, array $fieldBindings, CollectionInterface $collection): RepresentationBinding
	{
		$sourceBinding = new RepresentationBinding($collection);

		foreach ($fieldBindings as $fieldBinding) {
			$sourceBinding->addField($fieldBinding);
		}

		return $sourceBinding;
	}

	/**
	 * @param list<string> $sourcePath
	 *
	 * @return non-empty-array<string, string|int|float|bool>
	 */
	private function completeKeyValues(
		object $representation,
		RepresentationBinding $binding,
		CollectionInterface $collection,
		array $sourcePath,
		ProjectionIdentityColumns $identityColumns,
		array $sourceRow,
	): array {
		$pathsByField = [];

		foreach ($binding->getFields() as $fieldBinding) {
			$pathsByField[$fieldBinding->getFieldName()] = $fieldBinding->getPath();
		}

		$values = [];

		foreach ($collection->getPrimaryKey() as $fieldName) {
			$path = $pathsByField[$fieldName] ?? null;
			$value = $path !== null
				? $this->readValue($representation, $path, $sourceRow)
				: null;

			if ($value === null) {
				$resultKey = $identityColumns->get($sourcePath, $fieldName);

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
		RepresentationBinding $binding,
		CollectionInterface $collection,
		array $sourceRow,
	): array {
		$values = [];
		$primaryKey = array_flip($collection->getPrimaryKey());

		foreach ($binding->getFields() as $fieldBinding) {
			$fieldName = $fieldBinding->getFieldName();
			$value = $this->readValue($representation, $fieldBinding->getPath(), $sourceRow);

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
	 * @param array<string, RecordState> $recordsBySourcePath
	 *
	 * @return list<RepresentationFieldStateItem>
	 */
	private function buildFieldItems(
		RepresentationBinding $binding,
		array $recordsBySourcePath,
	): array {
		$items = [];

		foreach ($binding->getFields() as $fieldBinding) {
			$record = $recordsBySourcePath[$fieldBinding->getSourcePathKey()] ?? null;

			if (! $record instanceof RecordState) {
				throw new SyncException(sprintf(
					'Cannot adopt projection representation because field path "%s" targets unresolved source path "%s".',
					$fieldBinding->getPath(),
					$fieldBinding->getSourcePathKey(),
				));
			}

			$items[] = new RepresentationFieldStateItem(
				$fieldBinding,
				$record,
				$fieldBinding->getFieldName(),
				$record->getRevision()
			);
		}

		return $items;
	}
}
