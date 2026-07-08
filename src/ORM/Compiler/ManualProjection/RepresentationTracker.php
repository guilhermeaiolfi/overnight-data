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
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationBindingMerger;
use ON\Data\ORM\State\RepresentationFieldBinding;
use ON\Data\ORM\State\RepresentationFieldStateItem;
use ON\Data\ORM\State\RepresentationState;
use ON\Data\ORM\State\RepresentationStateStore;
use ON\Data\ORM\Sync\RepresentationStateFactory;
use stdClass;

final class RepresentationTracker
{
	public function __construct(
		private RepresentationStateStore $representations,
		private RecordStateStore $records,
		private RepresentationBindingMerger $bindingMerger = new RepresentationBindingMerger(),
		private RepresentationStateFactory $stateFactory = new RepresentationStateFactory(),
	) {
	}

	/**
	 * @param list<ProjectionFieldShape> $propertyShapes
	 */
	public function applyManualProjection(
		object $representation,
		RepresentationBinding $manualBinding,
		array $propertyShapes,
	): void {
		$state = $this->representations->get($representation);
		$recordsByPath = $this->recordsByPathFromShapes($propertyShapes);

		if ($state instanceof RepresentationState) {
			$binding = $this->bindingMerger->mergeManualOverlay($state->getBinding(), $manualBinding);
			$fieldItems = $state->getFieldItems();
			$relationItems = $state->getRelationItems();
		} else {
			$binding = $manualBinding;
			$fieldItems = [];
			$relationItems = [];
		}

		foreach ($binding->getFields() as $fieldBinding) {
			if ($this->hasFieldItem($fieldItems, $fieldBinding->getPath())) {
				continue;
			}

			$record = $this->resolveRecordForNewField($state, $fieldBinding, $recordsByPath);
			$fieldItems[] = new RepresentationFieldStateItem(
				$fieldBinding,
				$record,
				$fieldBinding->getFieldName(),
				$record->getRevision()
			);
		}

		if ($state instanceof RepresentationState) {
			$this->representations->remove($representation);
		}

		$this->representations->add($representation, new RepresentationState($binding, $fieldItems, $relationItems));
	}

	public function trackFlattenedAdapter(RecordState $record, RepresentationBinding $relatedBinding, object $ownerObject): object
	{
		$target = $ownerObject;
		$state = $this->representations->get($target);
		if ($state instanceof RepresentationState) {
			$existing = $this->records->getFromRepresentation($state);
			if ($existing !== $record) {
				$target = new stdClass();
			}
		}

		$this->trackTarget($target, $record, $relatedBinding);

		return $target;
	}

	public function trackTarget(object $target, RecordState $record, RepresentationBinding $relatedBinding): void
	{
		$state = $this->representations->get($target);
		if ($state instanceof RepresentationState) {
			return;
		}

		// Field items carry skip-when-missing bindings; the state binding stays as-is.
		$fieldItems = [];
		foreach ($relatedBinding->getFields() as $fieldBinding) {
			$fieldItems[] = new RepresentationFieldStateItem(
				$fieldBinding->withSkipWhenMissing(true),
				$record,
				$fieldBinding->getFieldName(),
				$record->getRevision()
			);
		}

		$this->representations->add($target, new RepresentationState($relatedBinding, $fieldItems));
	}

	public function trackAdapter(RecordState $record): object
	{
		// Private adapter for object-based relation state APIs; it is not a second identity system.
		$object = new stdClass();
		$binding = new RepresentationBinding($record->getCollection());
		foreach ($record->getCollection()->getPrimaryKey() as $fieldName) {
			if ($record->hasValue($fieldName)) {
				$object->{$fieldName} = $record->getValue($fieldName);
			}

			$binding->addField(new RepresentationFieldBinding($fieldName, $record->getCollection(), $fieldName, writable: false));
		}

		$this->representations->add($object, $this->stateFactory->fromRootRecord($binding, $record));

		return $object;
	}

	/**
	 * @return list<RecordState>
	 */
	public function recordsForCollection(RepresentationState $state, CollectionInterface $collection): array
	{
		$records = [];
		foreach ($state->getFieldItems() as $fieldItem) {
			if ($fieldItem->getBinding()->getCollectionName() === $collection->getName()) {
				$record = $fieldItem->getRecord();
				$records[$record->getStateHash()] = $record;
			}
		}

		foreach ($state->getRelationItems() as $relationItem) {
			if ($relationItem->getBinding()->getOwnerCollectionName() === $collection->getName()) {
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
		RepresentationFieldBinding $fieldBinding,
		array $recordsByPath,
	): RecordState {
		if ($state instanceof RepresentationState) {
			$resolved = $this->resolveRecordForFieldBinding($state, $fieldBinding);
			if ($resolved instanceof RecordState) {
				return $resolved;
			}
		}

		$explicit = $recordsByPath[$fieldBinding->getPath()] ?? null;
		if ($explicit instanceof RecordState) {
			if ($explicit->getCollection()->getName() !== $fieldBinding->getCollectionName()) {
				throw new StateException(sprintf(
					"Manual projection field '%s' resolved to a record of collection '%s' but the binding targets collection '%s'.",
					$fieldBinding->getPath(),
					$explicit->getCollection()->getName(),
					$fieldBinding->getCollectionName(),
				));
			}

			return $explicit;
		}

		throw new StateException(sprintf(
			"Cannot attach manual projection field '%s' because no concrete record state for source path '%s' could be resolved.",
			$fieldBinding->getPath(),
			$fieldBinding->getSourcePathKey(),
		));
	}

	private function resolveRecordForFieldBinding(
		RepresentationState $state,
		RepresentationFieldBinding $fieldBinding,
	): ?RecordState {
		if ($fieldBinding->isRootSource()) {
			return $state->getRootRecord();
		}

		$sourceKey = $fieldBinding->getSourcePathKey();
		foreach ($state->getFieldItems() as $item) {
			if ($item->getBinding()->getSourcePathKey() === $sourceKey) {
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
