<?php

declare(strict_types=1);

namespace ON\Data\ORM;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Key;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\Persistence\CommandExecutorInterface;
use ON\Data\ORM\Persistence\FlushExecutor;
use ON\Data\ORM\Persistence\FlushResult;
use ON\Data\ORM\Relation\ToManyRelationState;
use ON\Data\ORM\Relation\ToManyRelationStore;
use ON\Data\ORM\Relation\ToOneRelationState;
use ON\Data\ORM\Relation\ToOneRelationStore;
use ON\Data\ORM\State\RecordFieldRef;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RecordStateStore;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationFieldBinding;
use ON\Data\ORM\State\RepresentationState;
use ON\Data\ORM\State\RepresentationStore;
use ON\Data\ORM\Sync\GraphAdopter;
use ON\Data\ORM\Sync\RepresentationAdopter;
use ON\Data\ORM\Sync\RepresentationSyncer;
use ON\Data\ORM\Sync\RepresentationValueReader;
use ON\Data\ORM\Sync\SyncResult;
use stdClass;

final class Session
{
	private RecordStateStore $records;
	private RepresentationStore $representations;
	private ToManyRelationStore $relations;
	private ToOneRelationStore $references;
	private RepresentationAdopter $adopter;
	private GraphAdopter $graphAdopter;
	private FlushExecutor $flusher;
	private RepresentationSyncer $syncer;

	public function __construct(
		CommandExecutorInterface $executor,
		?FlushExecutor $flusher = null,
		?RepresentationSyncer $syncer = null,
	) {
		$this->records = new RecordStateStore();
		$this->representations = new RepresentationStore();
		$this->relations = new ToManyRelationStore();
		$this->references = new ToOneRelationStore();
		$this->adopter = new RepresentationAdopter($this->records, $this->representations);
		$this->graphAdopter = new GraphAdopter();
		$this->syncer = $syncer ?? new RepresentationSyncer();
		$this->flusher = $flusher ?? new FlushExecutor($executor, $this->syncer);
	}

	public function getRecords(): RecordStateStore
	{
		return $this->records;
	}

	public function getRepresentations(): RepresentationStore
	{
		return $this->representations;
	}

	public function getRelations(): ToManyRelationStore
	{
		return $this->relations;
	}

	public function getReferences(): ToOneRelationStore
	{
		return $this->references;
	}

	public function clear(): void
	{
		$this->records->clear();
		$this->representations->clear();
		$this->relations->clear();
		$this->references->clear();
	}

	public function trackRecord(RecordState $record): RecordState
	{
		$this->records->add($record);

		return $record;
	}

	/**
	 * @param array<string, mixed> $values
	 */
	public function trackNew(CollectionInterface $collection, array $values = []): RecordState
	{
		return $this->trackRecord(RecordState::new($collection, $values));
	}

	/**
	 * @param array<string, mixed> $values
	 */
	public function trackClean(Key $key, array $values): RecordState
	{
		return $this->trackRecord(RecordState::clean($key, $values));
	}

	public function identify(
		CollectionInterface $collection,
		Key|array $key,
		?object $representation = null,
		?RepresentationBinding $binding = null,
	): object {
		$key = $collection->getKey($key);
		$representation ??= $this->keyOnlyRepresentation($key);
		$binding ??= $this->keyOnlyBinding($collection);

		$existingState = $this->representations->get($representation);
		if ($existingState instanceof RepresentationState) {
			$record = $this->records->getFromRepresentation($existingState);
			if (! $record instanceof RecordState || ! $record->hasKey() || ! $record->getKey()?->equals($key)) {
				throw new StateException('Cannot identify representation because it is already tracked for a different record.');
			}

			return $representation;
		}

		$record = $this->records->getByKey($key);
		if ($record instanceof RecordState) {
			if ($record->isRemoved()) {
				throw new StateException(sprintf(
					"Cannot identify collection '%s' key '%s' because it is already tracked as removed.",
					$collection->getName(),
					$key->getDebugString()
				));
			}
		} else {
			$record = RecordState::clean($key, $this->identifyInitialValues($representation, $binding, $key));
		}

		$this->adopter->adopt($representation, $binding, $record);

		return $representation;
	}

	public function adopt(
		object $representation,
		RepresentationBinding $binding,
		RecordState $record,
	): RepresentationState {
		return $this->adopter->adopt($representation, $binding, $record);
	}

	public function removeRecord(RecordState $record): void
	{
		$this->remove($record);
	}

	public function remove(object $target): void
	{
		if ($target instanceof RecordState) {
			$this->records->add($target);
			$target->markRemoved();

			return;
		}

		$state = $this->representations->get($target);
		if (! $state instanceof RepresentationState) {
			throw new SyncException('Cannot remove an untracked representation object.');
		}

		$this->resolveSingleRecordForRemoval($state)->markRemoved();
	}

	public function trackRelation(ToManyRelationState $collection): ToManyRelationState
	{
		$this->relations->add($collection);

		return $collection;
	}

	public function trackReference(ToOneRelationState $reference): ToOneRelationState
	{
		$this->references->add($reference);

		return $reference;
	}

	public function sync(?object $representation = null, ?RepresentationBinding $binding = null): SyncResult
	{
		if ($representation !== null) {
			if (! $this->representations->has($representation) && ! $binding instanceof RepresentationBinding) {
				throw new SyncException('Cannot synchronize an untracked representation object without a root RepresentationBinding.');
			}

			$this->graphAdopter->adopt(
				$representation,
				$this->representations,
				$this->records,
				$this->relations,
				$this->references,
				$binding
			);

			return $this->syncer->sync($this->representations, $this->records, $this->relations, $this->references);
		}

		return $this->syncer->sync($this->representations, $this->records, $this->relations, $this->references, $representation);
	}

	public function flush(): FlushResult
	{
		return $this->flusher->flush($this->representations, $this->records, $this->relations, $this->references);
	}

	private function resolveSingleRecordForRemoval(RepresentationState $state): RecordState
	{
		$records = [];
		$binding = $state->getBinding();

		foreach ($binding->getFields() as $fieldBinding) {
			$field = $fieldBinding->getField();
			if ($field->hasState()) {
				$record = $field->getState();
				$records[$record->getStateHash()] = $record;
			}
		}

		foreach ($binding->getRelations() as $relationBinding) {
			$relation = $relationBinding->getRelation();
			if ($relation->hasState()) {
				$record = $relation->getState();
				$records[$record->getStateHash()] = $record;
			}
		}

		if ($records === []) {
			throw new StateException('Cannot remove representation because its binding does not resolve to a concrete record state.');
		}

		if (count($records) > 1) {
			throw new StateException('Cannot remove representation because its binding resolves to multiple record states.');
		}

		return array_values($records)[0];
	}

	private function keyOnlyRepresentation(Key $key): object
	{
		$representation = new stdClass();
		foreach ($key->getValues() as $fieldName => $value) {
			$representation->{$fieldName} = $value;
		}

		return $representation;
	}

	private function keyOnlyBinding(CollectionInterface $collection): RepresentationBinding
	{
		$binding = new RepresentationBinding();
		foreach ($collection->getPrimaryKey() as $fieldName) {
			$binding->addField(new RepresentationFieldBinding($fieldName, RecordFieldRef::template($collection, $fieldName)));
		}

		return $binding;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function identifyInitialValues(object $representation, RepresentationBinding $binding, Key $key): array
	{
		$values = $key->getValues();
		$reader = new RepresentationValueReader();
		foreach ($binding->getFields() as $fieldBinding) {
			$fieldName = $fieldBinding->getField()->getFieldName();

			try {
				$values[$fieldName] = $reader->readPath($representation, $fieldBinding->getPath());
			} catch (SyncException) {
			}
		}

		return $values;
	}
}
