<?php

declare(strict_types=1);

namespace ON\Data\ORM;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Key;
use ON\Data\ORM\Representation\Schema\Manual\Builder;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\Persistence\CommandExecutorInterface;
use ON\Data\ORM\Persistence\FlushExecutor;
use ON\Data\ORM\Persistence\FlushResult;
use ON\Data\ORM\Relation\RelationStateStore;
use ON\Data\ORM\Record\RecordState;
use ON\Data\ORM\Record\RecordStateStore;
use ON\Data\ORM\Representation\Schema\RepresentationFieldSchema;
use ON\Data\ORM\Representation\Schema\RepresentationSchema;
use ON\Data\ORM\Representation\State\RepresentationState;
use ON\Data\ORM\Representation\State\RepresentationStateStore;
use ON\Data\ORM\Representation\Sync\AdoptionRecordResolver;
use ON\Data\ORM\Representation\Sync\ExistingIntent;
use ON\Data\ORM\Representation\Sync\RepresentationReader;
use ON\Data\ORM\Representation\Sync\RepresentationStateAdoptionTrait;
use ON\Data\ORM\Representation\Sync\RepresentationSyncer;
use ON\Data\ORM\Representation\Sync\SyncResult;

final class Session
{
	use RepresentationStateAdoptionTrait;

	private SessionContext $context;
	private AdoptionRecordResolver $recordResolver;
	private FlushExecutor $flusher;
	private RepresentationSyncer $syncer;

	public function __construct(
		CommandExecutorInterface $executor,
		?FlushExecutor $flusher = null,
		?RepresentationSyncer $syncer = null,
		?SessionContext $context = null,
	) {
		$this->context = $context ?? new SessionContext();
		$this->recordResolver = new AdoptionRecordResolver(existingIntents: $this->context->getExistingIntents());
		$this->representationReader = new RepresentationReader();
		$this->syncer = $syncer ?? new RepresentationSyncer();
		$this->flusher = $flusher ?? new FlushExecutor($executor, $this->syncer);
	}

	public function getRecords(): RecordStateStore
	{
		return $this->context->getRecords();
	}

	public function getRepresentations(): RepresentationStateStore
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

	public function getRelations(): RelationStateStore
	{
		return $this->context->getRelations();
	}

	public function getToManyRelations(): RelationStateStore
	{
		return $this->context->getToManyRelations();
	}

	public function getToOneRelations(): RelationStateStore
	{
		return $this->context->getToOneRelations();
	}

	public function clear(): void
	{
		$this->context->clear();
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
		?RepresentationSchema $schema = null,
	): object {
		$key = $collection->getKey($key);
		$representation ??= RepresentationSchema::representationForKey($key);
		$schema ??= RepresentationSchema::forPrimaryKey($collection);

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
			$record = RecordState::clean($key, $this->recordResolver->initialValuesForKey($representation, $schema, $key));
		}

		$this->adopt(
			$representation,
			RepresentationState::fromRecords($schema, [
				RepresentationFieldSchema::sourcePathKey([]) => $record,
			]),
		);

		return $representation;
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

	public function sync(?object $representation = null, ?RepresentationSchema $schema = null): SyncResult
	{
		if ($representation !== null) {
			if (! $this->getRepresentations()->has($representation) && ! $schema instanceof RepresentationSchema) {
				throw new SyncException('Cannot synchronize an untracked representation object without a root RepresentationSchema.');
			}

			$this->adoptGraph($representation, $schema);

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
			throw new StateException('Cannot remove representation because its schema does not resolve to a concrete record state.');
		}

		if (count($records) > 1) {
			throw new StateException('Cannot remove representation because its schema resolves to multiple record states.');
		}

		return array_values($records)[0];
	}
}
