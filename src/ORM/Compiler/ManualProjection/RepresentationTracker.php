<?php

declare(strict_types=1);

namespace ON\Data\ORM\Compiler\ManualProjection;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\State\RecordFieldRef;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RecordStateStore;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationFieldBinding;
use ON\Data\ORM\State\RepresentationState;
use ON\Data\ORM\State\RepresentationStore;
use stdClass;

final class RepresentationTracker
{
	public function __construct(
		private RepresentationStore $representations,
		private RecordStateStore $records,
	) {
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

		$binding = $relatedBinding->applyToRecordState($record, skipWhenMissing: true);
		$this->representations->add($target, new RepresentationState($binding, [$record->getStateHash() => $record->getRevision()]));
	}

	public function trackAdapter(RecordState $record): object
	{
		// Private adapter for object-based relation state APIs; it is not a second identity system.
		$object = new stdClass();
		$binding = new RepresentationBinding();
		foreach ($record->getCollection()->getPrimaryKey() as $fieldName) {
			if ($record->hasValue($fieldName)) {
				$object->{$fieldName} = $record->getValue($fieldName);
			}

			$binding->addField(new RepresentationFieldBinding($fieldName, RecordFieldRef::forState($record, $fieldName), writable: false));
		}

		$this->representations->add($object, new RepresentationState($binding, [$record->getStateHash() => $record->getRevision()]));

		return $object;
	}

	/**
	 * @return list<RecordState>
	 */
	public function recordsForCollection(RepresentationState $state, CollectionInterface $collection): array
	{
		$records = [];
		foreach ($state->getBinding()->getFields() as $fieldBinding) {
			$field = $fieldBinding->getField();
			if ($field->hasState() && $field->getCollectionName() === $collection->getName()) {
				$record = $field->getState();
				$records[$record->getStateHash()] = $record;
			}
		}

		foreach ($state->getBinding()->getRelations() as $relationBinding) {
			$relation = $relationBinding->getRelation();
			if ($relation->hasState() && $relation->getCollectionName() === $collection->getName()) {
				$record = $relation->getState();
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
}
