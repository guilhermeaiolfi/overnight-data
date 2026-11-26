<?php

declare(strict_types=1);

namespace ON\Data\ORM;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Key;
use ON\Data\Mapper\ConversionGateway;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\History\FlushHistory;
use ON\Data\ORM\Persistence\CommandExecutorInterface;
use ON\Data\ORM\Persistence\FlushExecutor;
use ON\Data\ORM\Persistence\FlushResult;
use ON\Data\ORM\Record\RecordState;
use ON\Data\ORM\Record\RecordStateStore;
use ON\Data\ORM\Relation\RelationMemberIdentity;
use ON\Data\ORM\Relation\RelationStateStore;
use ON\Data\ORM\Relation\ToManyRelationState;
use ON\Data\ORM\Representation\State\Query\QueryResultHydrator;
use ON\Data\ORM\Representation\State\RepresentationIdentifier;
use ON\Data\ORM\Representation\State\RepresentationState;
use ON\Data\ORM\Representation\State\RepresentationStateStore;
use ON\Data\ORM\Representation\Sync\RepresentationAdoptionEngine;
use ON\Data\ORM\Representation\Sync\RepresentationAttachmentMode;
use ON\Data\ORM\Representation\Sync\RepresentationIntentLifecycle;
use ON\Data\ORM\Representation\Sync\RepresentationReader;
use ON\Data\ORM\Representation\Sync\RepresentationSyncer;
use ON\Data\ORM\Representation\Sync\SyncResult;
use ON\Data\Query\Result\WritablePreparation;
use ON\Data\Query\Result\WritableResultHandler;
use ON\Data\Query\SelectQuery;
use ON\Data\Schema\RepresentationRelationSchema;
use ON\Data\Schema\RepresentationSchema;

final class Session implements WritableResultHandler
{
	private SessionContext $context;
	private FlushExecutor $flusher;
	private RepresentationSyncer $syncer;
	private RepresentationAdoptionEngine $adoptionEngine;
	private QueryResultHydrator $hydrator;

	public function __construct(
		CommandExecutorInterface $executor,
		?FlushExecutor $flusher = null,
		?RepresentationSyncer $syncer = null,
		?SessionContext $context = null,
		?FlushHistory $history = null,
		private readonly ?ConversionGateway $gateway = null,
	) {
		$this->context = $context ?? new SessionContext();
		$this->hydrator = new QueryResultHydrator(
			$this->context,
			static fn (): RepresentationReader => new RepresentationReader($gateway),
		);
		$this->adoptionEngine = new RepresentationAdoptionEngine($gateway);
		$this->syncer = $syncer ?? new RepresentationSyncer(gateway: $gateway);
		$this->flusher = $flusher === null
			? new FlushExecutor($executor, $this->syncer, history: $history)
			: ($history === null ? $flusher : $flusher->withHistory($history));
	}

	public function prepare(SelectQuery $query): WritablePreparation
	{
		return $this->hydrator->prepare($query);
	}

	public function track(
		SelectQuery $query,
		WritablePreparation $preparation,
		array $rawRows,
		array $objects,
	): void {
		$this->hydrator->track($query, $preparation, $rawRows, $objects);
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
		return $this->context->attachRepresentation($representation, $state, $mode);
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

		$targetRecord = $this->recordForDetachTarget($targetObject);
		if ($state instanceof ToManyRelationState) {
			$state->remove($targetRecord ?? $targetObject);

			return;
		}

		$current = $state->getTarget();
		if ($current === $targetObject || ($targetRecord !== null && RelationMemberIdentity::same($current, $targetRecord))) {
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
		$keyOnly = $representation === null && $schema === null;
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

		if ($keyOnly) {
			$record = $this->getRecords()->getActive($key, sprintf(
				"Cannot identify representation because key '%s' is already tracked as removed.",
				$key->getDebugString(),
			)) ?? RecordState::clean($key, $key->getValues());
			$this->adopt($representation, RepresentationState::fromRecords($schema, ['' => $record]));
		} else {
			(new RepresentationIdentifier())->attach($representation, $schema, $key, $this->context, new RepresentationReader($this->gateway));
		}

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
			$this->adoptionEngine->attach($representation, $schema, $this->context);

			return $this->syncer->sync($this->context, $representation);
		}

		$this->applyPendingIntents();

		return $this->syncer->sync($this->context);
	}

	public function getHistory(): ?FlushHistory
	{
		return $this->flusher->getHistory();
	}

	public function flush(?string $label = null): FlushResult
	{
		return $this->flusher->flush($this->context, $label, $this->applyPendingIntents(...));
	}

	private function applyPendingIntents(): void
	{
		$pending = [];
		foreach ($this->context->getIntents()->getAll() as $representation => $intent) {
			$pending[] = $representation;
		}

		while ($pending !== []) {
			$before = count($pending);
			foreach ($pending as $index => $representation) {
				$intent = $this->context->getIntents()->get($representation);
				if ($intent === null) {
					unset($pending[$index]);

					continue;
				}
				if ($intent?->getSchema() === null && ! $this->getRepresentations()->has($representation)) {
					continue;
				}

				$this->adoptionEngine->attach($representation, null, $this->context);
				unset($pending[$index]);
			}

			if (count($pending) === $before) {
				throw new SyncException('Cannot apply pending write intents without a root RepresentationSchema. Supply a schema on update/create or stage an owning graph.');
			}
		}
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
}
