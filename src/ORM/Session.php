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
use ON\Data\ORM\Record\RecordState;
use ON\Data\ORM\Record\RecordStateStore;
use ON\Data\ORM\Relation\RelationStateStore;
use ON\Data\ORM\Relation\RelationTarget;
use ON\Data\ORM\Relation\ToManyRelationState;
use ON\Data\ORM\Representation\Schema\RepresentationFieldSchema;
use ON\Data\ORM\Representation\Schema\RepresentationRelationSchema;
use ON\Data\ORM\Representation\Schema\RepresentationSchema;
use ON\Data\ORM\Representation\State\Query\WritableQueryResultTracker;
use ON\Data\ORM\Representation\State\RepresentationState;
use ON\Data\ORM\Representation\State\RepresentationStateStore;
use ON\Data\ORM\Representation\Sync\AdoptionPolicy;
use ON\Data\ORM\Representation\Sync\AdoptionRecordResolver;
use ON\Data\ORM\Representation\Sync\RepresentationAdoptionContext;
use ON\Data\ORM\Representation\Sync\RepresentationAdoptionEngine;
use ON\Data\ORM\Representation\Sync\RepresentationAttachmentMode;
use ON\Data\ORM\Representation\Sync\RepresentationIntentLifecycle;
use ON\Data\ORM\Representation\Sync\RepresentationReader;
use ON\Data\ORM\Representation\Sync\RepresentationSyncer;
use ON\Data\ORM\Representation\Sync\StaticSourceIdentities;
use ON\Data\ORM\Representation\Sync\SyncResult;
use ON\Data\Query\Result\WritablePreparation;
use ON\Data\Query\Result\WritableResultHandler;
use ON\Data\Query\SelectQuery;

final class Session implements WritableResultHandler
{
	private SessionContext $context;
	private AdoptionRecordResolver $recordResolver;
	private FlushExecutor $flusher;
	private RepresentationSyncer $syncer;
	private RepresentationAdoptionEngine $adoptionEngine;
	private ?WritableQueryResultTracker $writableResultTracker = null;

	public function __construct(
		CommandExecutorInterface $executor,
		?FlushExecutor $flusher = null,
		?RepresentationSyncer $syncer = null,
		?SessionContext $context = null,
	) {
		$this->context = $context ?? new SessionContext();
		$this->recordResolver = new AdoptionRecordResolver(intents: $this->context->getIntents());
		$this->adoptionEngine = new RepresentationAdoptionEngine(
			new RepresentationReader(),
			$this->recordResolver,
		);
		$this->syncer = $syncer ?? new RepresentationSyncer();
		$this->flusher = $flusher ?? new FlushExecutor($executor, $this->syncer);
	}

	public function prepare(SelectQuery $query): WritablePreparation
	{
		return $this->writableResultTracker()->prepare($query);
	}

	public function track(
		SelectQuery $query,
		WritablePreparation $preparation,
		array $rawRows,
		array $objects,
	): void {
		$this->writableResultTracker()->track($query, $preparation, $rawRows, $objects);
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

	public function clear(): void
	{
		$this->context->clear();
	}

	/**
	 * Store a ready {@see RepresentationState}. Build/walk is {@see RepresentationAdoptionEngine::attach()}.
	 */
	public function adopt(
		object $representation,
		RepresentationState $state,
		RepresentationAttachmentMode $mode = RepresentationAttachmentMode::Add,
	): RepresentationState {
		$reps = $this->getRepresentations();
		$records = $this->getRecords();

		if ($mode === RepresentationAttachmentMode::Add && $reps->has($representation)) {
			throw new SyncException('Cannot attach representation because it is already tracked.');
		}

		foreach ($state->getUniqueRecords() as $record) {
			$records->add($record);
		}

		if ($mode === RepresentationAttachmentMode::Replace && $reps->has($representation)) {
			$reps->remove($representation);
		}

		$reps->add($representation, $state);

		return $state;
	}

	public function update(
		object $representation,
		?RepresentationSchema $schema = null,
	): IntentBuilder {
		$intent = $this->context->getIntents()->ensure(
			$representation,
			RepresentationIntentLifecycle::Update,
		);
		if ($schema instanceof RepresentationSchema) {
			$intent->setSchema($schema);
		}

		return new IntentBuilder($representation, $intent);
	}

	public function create(object $representation, ?RepresentationSchema $schema = null): IntentBuilder
	{
		$intent = $this->context->getIntents()->ensure(
			$representation,
			RepresentationIntentLifecycle::Create,
		);
		if ($schema instanceof RepresentationSchema) {
			$intent->setSchema($schema);
		}

		return new IntentBuilder($representation, $intent);
	}

	public function schemaOf(object $representation): RepresentationSchema
	{
		$state = $this->getRepresentations()->get($representation);
		if (! $state instanceof RepresentationState) {
			throw new SyncException('Cannot read schema because the representation is not tracked.');
		}

		return $state->getSchema();
	}

	/**
	 * Unlink $target from $owner's relation. Does not delete the target row.
	 *
	 * @param object|array<string, mixed>|string|int $target
	 */
	public function detach(object|array|string|int $target, object $owner, string $relation): void
	{
		$ownerState = $this->getRepresentations()->get($owner);
		if (! $ownerState instanceof RepresentationState) {
			throw new SyncException('Cannot detach because the owner representation is not tracked. Call sync() on the owner first.');
		}

		$ownerRecord = $ownerState->getOwnerRecord($relation);
		$relationSchema = $this->relationSchemaForDetach($ownerState, $ownerRecord, $relation);
		$relatedCollection = $relationSchema->getRelatedSchema()->getCollection();
		$targetObject = $this->resolveDetachTarget($target, $relatedCollection, $relationSchema->getRelatedSchema());

		$relations = $this->getRelations();
		$cardinality = $relationSchema->getCardinality();
		$state = $relations->getOrCreate(
			$ownerRecord,
			$relation,
			$cardinality,
			$relationSchema->getRelatedSchema(),
		);

		if ($state instanceof ToManyRelationState) {
			$targetRecord = $this->recordForDetachTarget($targetObject);
			if ($targetRecord instanceof RecordState) {
				$state->removeTarget(RelationTarget::record($targetRecord));
			} else {
				$state->remove($targetObject);
			}

			return;
		}

		$current = $state->getTargetRelation();
		if ($current === null) {
			return;
		}

		$want = RelationTarget::representation($targetObject);
		$targetRecord = $this->recordForDetachTarget($targetObject);
		if ($current->equals($want) || ($targetRecord instanceof RecordState && $current->equals(RelationTarget::record($targetRecord)))) {
			$state->clear();
		}
	}

	private function recordForDetachTarget(object $targetObject): ?RecordState
	{
		$tracked = $this->getRepresentations()->get($targetObject);
		if (! $tracked instanceof RepresentationState) {
			return null;
		}

		$record = $tracked->getSingleRecord();

		return $record instanceof RecordState ? $record : null;
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
			$record = $existingState->getSingleRecord();
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

		$state->requireSingleRecord()->markRemoved();
	}

	public function sync(?object $representation = null, ?RepresentationSchema $schema = null): SyncResult
	{
		if ($representation !== null) {
			$this->applyIntent($representation, $schema);

			return $this->syncer->sync($this->context, $representation);
		}

		return $this->syncer->sync($this->context);
	}

	public function flush(): FlushResult
	{
		return $this->flusher->flush($this->context);
	}

	private function applyIntent(object $representation, ?RepresentationSchema $schema): void
	{
		$intent = $this->context->getIntents()->get($representation);
		$resolvedSchema = $schema
			?? $intent?->getSchema()
			?? ($this->getRepresentations()->has($representation)
				? $this->getRepresentations()->get($representation)?->getSchema()
				: null);

		if ($intent !== null && $intent->isFlatProjection($resolvedSchema)) {
			if (! $resolvedSchema instanceof RepresentationSchema) {
				throw new SyncException('Cannot synchronize a flat projection intent without a RepresentationSchema.');
			}

			$this->adoptionEngine->attach(
				$representation,
				new RepresentationAdoptionContext(
					schema: $resolvedSchema,
					policy: $intent->isCreate() ? AdoptionPolicy::Create : AdoptionPolicy::Patch,
					identities: StaticSourceIdentities::fromIntent($resolvedSchema, $intent),
					intent: $intent,
				),
				$this->getRecords(),
				$this->getRepresentations(),
				$this->getRelations(),
				RepresentationAttachmentMode::Replace,
			);
			$this->context->getIntents()->remove($representation);

			return;
		}

		if (! $resolvedSchema instanceof RepresentationSchema) {
			throw new SyncException('Cannot synchronize an untracked representation object without a root RepresentationSchema.');
		}

		$policy = $intent?->isUpdate() === true
			? AdoptionPolicy::Patch
			: AdoptionPolicy::Create;
		$this->adoptionEngine->attach(
			$representation,
			new RepresentationAdoptionContext(
				schema: $resolvedSchema,
				policy: $policy,
			),
			$this->getRecords(),
			$this->getRepresentations(),
		);
		$this->context->getIntents()->remove($representation);
	}

	/**
	 * @param object|array<string, mixed>|string|int $target
	 */
	private function resolveDetachTarget(
		object|array|string|int $target,
		CollectionInterface $collection,
		RepresentationSchema $relatedSchema,
	): object {
		if ($target instanceof Key) {
			return $this->identify($collection, $target, schema: $relatedSchema);
		}

		if (is_object($target)) {
			if (! $this->getRepresentations()->has($target)) {
				$pk = $collection->getPrimaryKey();
				if (count($pk) === 1 && isset($target->{$pk[0]})) {
					return $this->identify($collection, [$pk[0] => $target->{$pk[0]}], $target, $relatedSchema);
				}

				throw new SyncException('Cannot detach an untracked target object. Pass a key or identify() it first.');
			}

			return $target;
		}

		if (is_array($target)) {
			return $this->identify($collection, $target, schema: $relatedSchema);
		}

		$pk = $collection->getPrimaryKey();
		if (count($pk) !== 1) {
			throw new StateException(sprintf(
				"Scalar detach target requires a single-field primary key on collection '%s'.",
				$collection->getName(),
			));
		}

		return $this->identify($collection, [$pk[0] => $target], schema: $relatedSchema);
	}

	private function relationSchemaForDetach(
		RepresentationState $ownerState,
		RecordState $ownerRecord,
		string $relation,
	): RepresentationRelationSchema {
		if ($ownerState->getSchema()->hasRelation($relation)) {
			return $ownerState->getSchema()->getRelation($relation);
		}

		if (! $ownerRecord->getCollection()->hasRelation($relation)) {
			throw new StateException(sprintf(
				"Collection '%s' has no relation '%s'.",
				$ownerRecord->getCollection()->getName(),
				$relation,
			));
		}

		$definition = $ownerRecord->getCollection()->getRelation($relation);
		$related = RepresentationSchema::forPrimaryKey($definition->getCollection());

		return new RepresentationRelationSchema(
			$relation,
			$ownerRecord->getCollection(),
			$relation,
			$related,
		);
	}

	private function writableResultTracker(): WritableQueryResultTracker
	{
		return $this->writableResultTracker ??= new WritableQueryResultTracker($this);
	}
}
