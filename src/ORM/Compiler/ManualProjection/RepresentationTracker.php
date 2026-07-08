<?php

declare(strict_types=1);

namespace ON\Data\ORM\Compiler\ManualProjection;

/**
 * Registers manual projection targets and adapters in RepresentationStateStore.
 *
 * Exists to bridge RecordState identity to PHP objects (including flattened
 * adapters and private stdClass adapters for relation APIs) without a separate
 * projection identity system.
 */
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\ORM\Compiler\ProjectionFieldShape;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RecordStateStore;
use ON\Data\ORM\State\RepresentationFieldSchema;
use ON\Data\ORM\State\RepresentationFieldStateItem;
use ON\Data\ORM\State\RepresentationSchema;
use ON\Data\ORM\State\RepresentationSchemaMerger;
use ON\Data\ORM\State\RepresentationState;
use ON\Data\ORM\State\RepresentationStateStore;
use ON\Data\ORM\Sync\RepresentationStateFactory;
use stdClass;

final class RepresentationTracker
{
	public function __construct(
		private RepresentationStateStore $representations,
		private RecordStateStore $records,
		private RepresentationSchemaMerger $schemaMerger = new RepresentationSchemaMerger(),
		private RepresentationStateFactory $stateFactory = new RepresentationStateFactory(),
	) {
	}

	/**
	 * @param list<ProjectionFieldShape> $propertyShapes
	 */
	public function applyManualProjection(
		object $representation,
		RepresentationSchema $manualSchema,
		array $propertyShapes,
	): void {
		$state = $this->representations->get($representation);
		$recordsByPath = $this->recordsByPathFromShapes($propertyShapes);

		if ($state instanceof RepresentationState) {
			$schema = $this->schemaMerger->mergeManualOverlay($state->getSchema(), $manualSchema);
			$fieldItems = $state->getFieldItems();
			$relationItems = $state->getRelationItems();
		} else {
			$schema = $manualSchema;
			$fieldItems = [];
			$relationItems = [];
		}

		foreach ($schema->getFields() as $fieldSchema) {
			if ($this->hasFieldItem($fieldItems, $fieldSchema->getPath())) {
				continue;
			}

			$record = $this->resolveRecordForNewField($state, $fieldSchema, $recordsByPath);
			$fieldItems[] = $this->stateFactory->createFieldItem($fieldSchema, $record);
		}

		if ($state instanceof RepresentationState) {
			$this->representations->remove($representation);
		}

		$this->representations->add($representation, new RepresentationState($schema, $fieldItems, $relationItems));
	}

	public function trackFlattenedAdapter(RecordState $record, RepresentationSchema $relatedSchema, object $ownerObject): object
	{
		$target = $ownerObject;
		$state = $this->representations->get($target);
		if ($state instanceof RepresentationState) {
			$existing = $this->records->getFromRepresentation($state);
			if ($existing !== $record) {
				$target = new stdClass();
			}
		}

		$this->trackTarget($target, $record, $relatedSchema);

		return $target;
	}

	public function trackTarget(object $target, RecordState $record, RepresentationSchema $relatedSchema): void
	{
		$state = $this->representations->get($target);
		if ($state instanceof RepresentationState) {
			return;
		}

		$this->representations->add(
			$target,
			$this->stateFactory->fromRootRecordFields($relatedSchema, $record, skipWhenMissing: true)
		);
	}

	public function trackAdapter(RecordState $record): object
	{
		// Private adapter for object-based relation state APIs; it is not a second identity system.
		$object = new stdClass();
		$schema = new RepresentationSchema($record->getCollection());
		foreach ($record->getCollection()->getPrimaryKey() as $fieldName) {
			if ($record->hasValue($fieldName)) {
				$object->{$fieldName} = $record->getValue($fieldName);
			}

			$schema->addField(new RepresentationFieldSchema($fieldName, $record->getCollection(), $fieldName, writable: false));
		}

		$this->representations->add($object, $this->stateFactory->fromRootRecordFields($schema, $record));

		return $object;
	}

	/**
	 * @return list<RecordState>
	 */
	public function recordsForCollection(RepresentationState $state, CollectionInterface $collection): array
	{
		$records = [];
		foreach ($state->getFieldItems() as $fieldItem) {
			if ($fieldItem->getSchema()->getCollectionName() === $collection->getName()) {
				$record = $fieldItem->getRecord();
				$records[$record->getStateHash()] = $record;
			}
		}

		foreach ($state->getRelationItems() as $relationItem) {
			if ($relationItem->getSchema()->getOwnerCollectionName() === $collection->getName()) {
				$record = $relationItem->getOwnerRecord();
				$records[$record->getStateHash()] = $record;
			}
		}

		return array_values($records);
	}

	public function singleRecordForTrackedTarget(object $target, CollectionInterface $collection, string $prefix): RecordState
	{
		$state = $this->representations->get($target);
		if (! $state instanceof RepresentationState) {
			throw new SyncException($prefix . ' because the target representation is not tracked.');
		}

		$matches = $this->recordsForCollection($state, $collection);
		if ($matches === []) {
			throw new StateException($prefix . ' because the target has no matching tracked record state.');
		}

		if (count($matches) > 1) {
			throw new StateException($prefix . ' because the matching target record state is ambiguous.');
		}

		return $matches[0];
	}

	/**
	 * @param array<string, RecordState> $recordsByPath
	 */
	private function resolveRecordForNewField(
		?RepresentationState $state,
		RepresentationFieldSchema $fieldSchema,
		array $recordsByPath,
	): RecordState {
		if ($state instanceof RepresentationState) {
			$resolved = $this->resolveRecordForFieldSchema($state, $fieldSchema);
			if ($resolved instanceof RecordState) {
				return $resolved;
			}
		}

		$explicit = $recordsByPath[$fieldSchema->getPath()] ?? null;
		if ($explicit instanceof RecordState) {
			if ($explicit->getCollection()->getName() !== $fieldSchema->getCollectionName()) {
				throw new StateException(sprintf(
					"Manual projection field '%s' resolved to a record of collection '%s' but the schema targets collection '%s'.",
					$fieldSchema->getPath(),
					$explicit->getCollection()->getName(),
					$fieldSchema->getCollectionName(),
				));
			}

			return $explicit;
		}

		throw new StateException(sprintf(
			"Cannot attach manual projection field '%s' because no concrete record state for source path '%s' could be resolved.",
			$fieldSchema->getPath(),
			$fieldSchema->getSourcePathKey(),
		));
	}

	private function resolveRecordForFieldSchema(
		RepresentationState $state,
		RepresentationFieldSchema $fieldSchema,
	): ?RecordState {
		if ($fieldSchema->isRootSource()) {
			return $state->getRootRecord();
		}

		$sourceKey = $fieldSchema->getSourcePathKey();
		foreach ($state->getFieldItems() as $item) {
			if ($item->getSchema()->getSourcePathKey() === $sourceKey) {
				return $item->getRecord();
			}
		}

		return null;
	}

	/**
	 * @param list<ProjectionFieldShape> $propertyShapes
	 *
	 * @return array<string, RecordState>
	 */
	private function recordsByPathFromShapes(array $propertyShapes): array
	{
		$records = [];
		foreach ($propertyShapes as $shape) {
			$source = $shape->getSource();
			if ($source instanceof PropertySource) {
				$records[$shape->getPublicPath()] = $source->getTargetRecord();
			}
		}

		return $records;
	}

	/**
	 * @param list<RepresentationFieldStateItem> $items
	 */
	private function hasFieldItem(array $items, string $path): bool
	{
		foreach ($items as $item) {
			if ($item->getPath() === $path) {
				return true;
			}
		}

		return false;
	}
}
