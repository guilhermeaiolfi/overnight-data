<?php

declare(strict_types=1);

namespace ON\Data\ORM\Query;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\SessionContext;
use ON\Data\ORM\State\RecordFieldRef;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RecordStateStore;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationFieldBinding;
use ON\Data\ORM\State\RepresentationState;
use ON\Data\ORM\Sync\RepresentationReader;
use ON\Data\Query\SelectQuery;

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
		SelectQuery $query,
		array $sourceRow,
		SessionContext $context,
	): RepresentationState {
		unset($query);

		if ($binding->getRelations() !== []) {
			throw new StateException('Cannot adopt flat projection representation because the binding contains relation bindings.');
		}

		$records = $context->getRecords();
		$representations = $context->getRepresentations();

		if ($representations->has($representation)) {
			throw new SyncException('Cannot adopt representation because it is already tracked.');
		}

		$recordsByCollection = $this->resolveRecords($representation, $binding, $records, $sourceRow);
		$appliedBinding = $this->applyProjectionBinding($binding, $recordsByCollection);
		$state = new RepresentationState(
			$appliedBinding,
			$this->buildBaselineRevisions($appliedBinding, $recordsByCollection),
		);

		foreach ($recordsByCollection as $record) {
			$records->add($record);
		}

		$representations->add($representation, $state);

		return $state;
	}

	/**
	 * @param array<string, RecordState> $recordsByCollection
	 */
	private function applyProjectionBinding(
		RepresentationBinding $binding,
		array $recordsByCollection,
	): RepresentationBinding {
		$applied = new RepresentationBinding();

		foreach ($binding->getFields() as $fieldBinding) {
			$field = $fieldBinding->getField();

			if (! $field->isTemplate()) {
				throw new StateException(sprintf(
					"Representation binding path '%s' already targets a concrete record.",
					$fieldBinding->getPath(),
				));
			}

			$record = $recordsByCollection[$field->getCollectionName()] ?? null;

			if (! $record instanceof RecordState) {
				throw new SyncException(sprintf(
					'Cannot adopt projection representation because field path "%s" targets unresolved collection "%s".',
					$fieldBinding->getPath(),
					$field->getCollectionName(),
				));
			}

			$applied->addField($fieldBinding->withField(RecordFieldRef::forState($record, $field->getFieldName())));
		}

		foreach ($binding->getExpressions() as $expressionBinding) {
			$applied->addExpression($expressionBinding);
		}

		return $applied;
	}

	/**
	 * @return array<string, RecordState>
	 */
	private function resolveRecords(
		object $representation,
		RepresentationBinding $binding,
		RecordStateStore $records,
		array $sourceRow,
	): array {
		$resolved = [];

		foreach ($this->groupFieldBindingsByCollection($binding) as $collectionName => $fieldBindings) {
			$collection = $fieldBindings[0]->getField()->getCollection();
			$collectionBinding = $this->bindingForFields($binding, $fieldBindings);
			$resolved[$collectionName] = $this->resolveRecord(
				$representation,
				$collectionBinding,
				$collection,
				$records,
				$sourceRow,
			);
		}

		return $resolved;
	}

	private function resolveRecord(
		object $representation,
		RepresentationBinding $binding,
		CollectionInterface $collection,
		RecordStateStore $records,
		array $sourceRow,
	): RecordState {
		$values = $this->initialValues($representation, $binding, $collection, $sourceRow);
		$keyValues = $this->completeKeyValues($representation, $binding, $collection, $sourceRow);
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
	private function groupFieldBindingsByCollection(RepresentationBinding $binding): array
	{
		/** @var array<string, list<RepresentationFieldBinding>> $grouped */
		$grouped = [];

		foreach ($binding->getFields() as $fieldBinding) {
			$collectionName = $fieldBinding->getField()->getCollectionName();
			$grouped[$collectionName][] = $fieldBinding;
		}

		return $grouped;
	}

	/**
	 * @param list<RepresentationFieldBinding> $fieldBindings
	 */
	private function bindingForFields(RepresentationBinding $binding, array $fieldBindings): RepresentationBinding
	{
		$paths = [];
		foreach ($fieldBindings as $fieldBinding) {
			$paths[$fieldBinding->getPath()] = true;
		}

		$collectionBinding = new RepresentationBinding();

		foreach ($binding->getFields() as $fieldBinding) {
			if (isset($paths[$fieldBinding->getPath()])) {
				$collectionBinding->addField($fieldBinding);
			}
		}

		return $collectionBinding;
	}

	/**
	 * @return non-empty-array<string, string|int|float|bool>
	 */
	private function completeKeyValues(
		object $representation,
		RepresentationBinding $binding,
		CollectionInterface $collection,
		array $sourceRow,
	): array {
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
				throw new StateException(sprintf(
					"Cannot adopt projection representation for collection '%s' because primary key field '%s' is not bound.",
					$collection->getName(),
					$fieldName,
				));
			}

			$value = $this->readValue($representation, $pathsByField[$fieldName], $sourceRow);

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
			$field = $fieldBinding->getField();

			if ($field->getCollectionName() !== $collection->getName()) {
				continue;
			}

			$fieldName = $field->getFieldName();
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
	 * @param array<string, RecordState> $recordsByCollection
	 *
	 * @return array<string, int>
	 */
	private function buildBaselineRevisions(
		RepresentationBinding $binding,
		array $recordsByCollection,
	): array {
		$baselineRevisions = [];

		foreach ($binding->getFields() as $fieldBinding) {
			$recordHash = $fieldBinding->getField()->getRecordHash();

			if (! array_key_exists($recordHash, $baselineRevisions)) {
				$baselineRevisions[$recordHash] = $fieldBinding->getField()->getState()->getRevision();
			}
		}

		foreach ($recordsByCollection as $record) {
			$baselineRevisions[$record->getStateHash()] ??= $record->getRevision();
		}

		return $baselineRevisions;
	}
}
