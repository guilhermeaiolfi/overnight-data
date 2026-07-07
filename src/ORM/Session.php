<?php

declare(strict_types=1);

namespace ON\Data\ORM;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Key;
use ON\Data\ORM\Compiler\ManualProjection\Builder;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\Persistence\CommandExecutorInterface;
use ON\Data\ORM\Persistence\FlushExecutor;
use ON\Data\ORM\Persistence\FlushResult;
use ON\Data\ORM\Relation\RelationStateStore;
use ON\Data\ORM\Relation\ToManyRelationState;
use ON\Data\ORM\Relation\ToOneRelationState;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RecordStateStore;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationFieldBinding;
use ON\Data\ORM\State\RepresentationState;
use ON\Data\ORM\State\RepresentationStore;
use ON\Data\ORM\Sync\AdoptionRecordResolver;
use ON\Data\ORM\Sync\ExistingIntent;
use ON\Data\ORM\Sync\GraphAdopter;
use ON\Data\ORM\Sync\RepresentationAdopter;
use ON\Data\ORM\Sync\RepresentationSyncer;
use ON\Data\ORM\Sync\SyncResult;
use stdClass;

final class Session
{
	private SessionContext $context;
	private RepresentationAdopter $adopter;
	private AdoptionRecordResolver $recordResolver;
	private GraphAdopter $graphAdopter;
	private FlushExecutor $flusher;
	private RepresentationSyncer $syncer;

	public function __construct(
		CommandExecutorInterface $executor,
		?FlushExecutor $flusher = null,
		?RepresentationSyncer $syncer = null,
	) {
		$this->context = new SessionContext();
		$this->adopter = new RepresentationAdopter($this->getRecords(), $this->getRepresentations());
		$this->recordResolver = new AdoptionRecordResolver(existingIntents: $this->context->getExistingIntents());
		$this->graphAdopter = new GraphAdopter(records: $this->recordResolver);
		$this->syncer = $syncer ?? new RepresentationSyncer();
		$this->flusher = $flusher ?? new FlushExecutor($executor, $this->syncer);
	}

	public function getRecords(): RecordStateStore
	{
		return $this->context->getRecords();
	}

	public function getRepresentations(): RepresentationStore
	{
		return $this->context->getRepresentations();
	}

	/**
	 * Internal extension point for ORM query result tracking.
	 */
	public function getContext(): SessionContext
	{
		return $this->context;
	}

	/**
	 * @return RelationStateStore<ToManyRelationState>
	 */
	public function getToManyRelations(): RelationStateStore
	{
		return $this->context->getToManyRelations();
	}

	/**
	 * @return RelationStateStore<ToOneRelationState>
	 */
	public function getToOneRelations(): RelationStateStore
	{
		return $this->context->getToOneRelations();
	}

	public function clear(): void
	{
		$this->context->clear();
	}

	public function trackRecord(RecordState $record): RecordState
	{
		$this->getRecords()->add($record);

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

	public function existing(object $representation): ExistingIntent
	{
		$this->context->getExistingIntents()->mark($representation);

		return new ExistingIntent($representation);
	}

	public function projection(object $representation): Builder
	{
		return new Builder($this, $representation);
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

		$existingState = $this->getRepresentations()->get($representation);
		if ($existingState instanceof RepresentationState) {
			$record = $this->getRecords()->getFromRepresentation($existingState);
			if (! $record instanceof RecordState || ! $record->hasKey() || ! $record->getKey()?->equals($key)) {
				throw new StateException('Cannot identify representation because it is already tracked for a different record.');
			}

			return $representation;
		}

		$record = $this->getRecords()->getByKey($key);
		if ($record instanceof RecordState) {
			if ($record->isRemoved()) {
				throw new StateException(sprintf(
					"Cannot identify collection '%s' key '%s' because it is already tracked as removed.",
					$collection->getName(),
					$key->getDebugString()
				));
			}
		} else {
			$record = RecordState::clean($key, $this->recordResolver->initialValuesForKey($representation, $binding, $key));
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
			$this->getRecords()->add($target);
			$target->markRemoved();

			return;
		}

		$state = $this->getRepresentations()->get($target);
		if (! $state instanceof RepresentationState) {
			throw new SyncException('Cannot remove an untracked representation object.');
		}

		$this->resolveSingleRecordForRemoval($state)->markRemoved();
	}

	public function trackToManyRelation(ToManyRelationState $relation): ToManyRelationState
	{
		$this->getToManyRelations()->add($relation);

		return $relation;
	}

	public function trackToOneRelation(ToOneRelationState $relation): ToOneRelationState
	{
		$this->getToOneRelations()->add($relation);

		return $relation;
	}

	public function sync(?object $representation = null, ?RepresentationBinding $binding = null): SyncResult
	{
		if ($representation !== null) {
			if (! $this->getRepresentations()->has($representation) && ! $binding instanceof RepresentationBinding) {
				throw new SyncException('Cannot synchronize an untracked representation object without a root RepresentationBinding.');
			}

			$this->graphAdopter->adopt(
				$representation,
				$this->context->getRepresentations(),
				$this->context->getRecords(),
				$binding
			);

			return $this->syncer->sync($this->context, $representation);
		}

		return $this->syncer->sync($this->context);
	}

	public function flush(): FlushResult
	{
		return $this->flusher->flush($this->context);
	}

	private function resolveSingleRecordForRemoval(RepresentationState $state): RecordState
	{
		$records = [];
		foreach ($state->getFieldItems() as $fieldItem) {
			$record = $fieldItem->getRecord();
			$records[$record->getStateHash()] = $record;
		}

		foreach ($state->getRelationItems() as $relationItem) {
			$record = $relationItem->getOwnerRecord();
			$records[$record->getStateHash()] = $record;
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
		$binding = new RepresentationBinding($collection);
		foreach ($collection->getPrimaryKey() as $fieldName) {
			$binding->addField(new RepresentationFieldBinding($fieldName, $collection, $fieldName));
		}

		return $binding;
	}
}
