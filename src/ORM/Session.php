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
use ON\Data\ORM\Relation\ToOneRelationState;
use ON\Data\ORM\Representation\Schema\RepresentationFieldSchema;
use ON\Data\ORM\Representation\Schema\RepresentationRelationSchema;
use ON\Data\ORM\Representation\Schema\RepresentationSchema;
use ON\Data\ORM\Representation\Schema\Shape\RepresentationSource;
use ON\Data\ORM\Representation\State\Projection\ProjectionRepresentationStateBuilder;
use ON\Data\ORM\Representation\State\Query\MutableQueryResultTracker;
use ON\Data\ORM\Representation\State\RepresentationState;
use ON\Data\ORM\Representation\State\RepresentationStateStore;
use ON\Data\ORM\Representation\Sync\AdoptionRecordResolver;
use ON\Data\ORM\Representation\Sync\RepresentationAttachmentMode;
use ON\Data\ORM\Representation\Sync\RepresentationIntent;
use ON\Data\ORM\Representation\Sync\RepresentationIntentLifecycle;
use ON\Data\ORM\Representation\Sync\RepresentationReader;
use ON\Data\ORM\Representation\Sync\RepresentationStateAdoptionTrait;
use ON\Data\ORM\Representation\Sync\RepresentationSyncer;
use ON\Data\ORM\Representation\Sync\SyncResult;
use ON\Data\Query\Result\MutablePreparation;
use ON\Data\Query\Result\MutableResultHandler;
use ON\Data\Query\SelectQuery;

final class Session implements MutableResultHandler
{
	use RepresentationStateAdoptionTrait;

	private SessionContext $context;
	private AdoptionRecordResolver $recordResolver;
	private FlushExecutor $flusher;
	private RepresentationSyncer $syncer;
	private ProjectionRepresentationStateBuilder $projectionStateBuilder;
	private ?MutableQueryResultTracker $mutableResultTracker = null;

	public function __construct(
		CommandExecutorInterface $executor,
		?FlushExecutor $flusher = null,
		?RepresentationSyncer $syncer = null,
		?SessionContext $context = null,
	) {
		$this->context = $context ?? new SessionContext();
		$this->recordResolver = new AdoptionRecordResolver(intents: $this->context->getIntents());
		$this->representationReader = new RepresentationReader();
		$this->syncer = $syncer ?? new RepresentationSyncer();
		$this->projectionStateBuilder = new ProjectionRepresentationStateBuilder($this->representationReader);
		$this->flusher = $flusher ?? new FlushExecutor($executor, $this->syncer);
	}

	public function prepare(SelectQuery $query): MutablePreparation
	{
		return $this->mutableResultTracker()->prepare($query);
	}

	public function track(
		SelectQuery $query,
		MutablePreparation $preparation,
		array $rawRows,
		array $objects,
	): void {
		$this->mutableResultTracker()->track($query, $preparation, $rawRows, $objects);
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

		$ownerRecord = $this->resolveOwnerRecordForRelation($ownerState, $relation);
		$relationSchema = $this->relationSchemaForDetach($ownerState, $ownerRecord, $relation);
		$relatedCollection = $relationSchema->getRelatedSchema()->getCollection();
		$targetObject = $this->resolveDetachTarget($target, $relatedCollection, $relationSchema->getRelatedSchema());

		$relations = $this->getRelations();
		$state = $relations->get($ownerRecord, $relation);
		$cardinality = $relationSchema->getCardinality();

		if ($cardinality->isMany()) {
			if ($state === null) {
				$state = new ToManyRelationState($ownerRecord, $relation, $relationSchema->getRelatedSchema());
				$relations->add($state);
			} elseif (! $state instanceof ToManyRelationState) {
				throw new StateException(sprintf("Relation '%s' is already tracked with incompatible cardinality.", $relation));
			}

			$targetRecord = $this->recordForDetachTarget($targetObject);
			if ($targetRecord instanceof RecordState) {
				$state->removeTarget(RelationTarget::record($targetRecord));
			} else {
				$state->remove($targetObject);
			}

			return;
		}

		if ($state === null) {
			$state = new ToOneRelationState($ownerRecord, $relation, $relationSchema->getRelatedSchema());
			$relations->add($state);
		} elseif (! $state instanceof ToOneRelationState) {
			throw new StateException(sprintf("Relation '%s' is already tracked with incompatible cardinality.", $relation));
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

		$record = $this->getRecords()->getFromRepresentation($tracked);

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

		if ($intent !== null && $this->isFlatProjectionIntent($intent, $resolvedSchema)) {
			if (! $resolvedSchema instanceof RepresentationSchema) {
				throw new SyncException('Cannot synchronize a flat projection intent without a RepresentationSchema.');
			}

			$state = $this->projectionStateBuilder->build(
				$representation,
				$intent,
				$resolvedSchema,
				$this->getRecords(),
				$this->getRelations(),
			);
			$this->adopt($representation, $state, RepresentationAttachmentMode::Replace);
			$this->context->getIntents()->remove($representation);

			return;
		}

		if (! $this->getRepresentations()->has($representation) && ! $resolvedSchema instanceof RepresentationSchema) {
			throw new SyncException('Cannot synchronize an untracked representation object without a root RepresentationSchema.');
		}

		$this->adoptGraph($representation, $resolvedSchema);
		$this->context->getIntents()->remove($representation);
	}

	private function isFlatProjectionIntent(
		?RepresentationIntent $intent,
		?RepresentationSchema $schema,
	): bool {
		if (! $intent instanceof RepresentationIntent) {
			return false;
		}

		if ($intent->getFlatOps() !== []) {
			return true;
		}

		if (! $schema instanceof RepresentationSchema) {
			return false;
		}

		if ($schema->getRelations() !== []) {
			return false;
		}

		// Inbound save maps (SelectQuery::projection / schema overlay) without relation
		// branches use the flat projection binder — including single-collection roots.
		if ($intent->getSchema() instanceof RepresentationSchema) {
			return true;
		}

		$sources = RepresentationSource::fromRepresentationSchema($schema);

		return count($sources) > 1 || ($sources !== [] && ! $sources[0]->isRoot());
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

	private function resolveOwnerRecordForRelation(
		RepresentationState $ownerState,
		string $relation,
	): RecordState {
		if ($ownerState->getSchema()->hasRelation($relation)) {
			$relationSchema = $ownerState->getSchema()->getRelation($relation);
			foreach ($ownerState->getRelationItems() as $item) {
				if ($item->getSchema()->getPath() === $relationSchema->getPath()) {
					return $item->getOwnerRecord();
				}
			}

			$root = $ownerState->getRootRecord();
			if ($root instanceof RecordState) {
				return $root;
			}
		}

		$root = $ownerState->getRootRecord();
		if ($root instanceof RecordState && $root->getCollection()->hasRelation($relation)) {
			return $root;
		}

		$matches = $ownerState->getRecordsForCollection(
			$ownerState->getSchema()->getCollection(),
		);
		if (count($matches) === 1) {
			return $matches[0];
		}

		throw new StateException(sprintf(
			"Cannot resolve owner record for relation '%s' on the tracked representation.",
			$relation,
		));
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

	private function mutableResultTracker(): MutableQueryResultTracker
	{
		return $this->mutableResultTracker ??= new MutableQueryResultTracker($this);
	}
}
