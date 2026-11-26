<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Sync;

use ON\Data\Definition\Relation\RelationCardinality;
use ON\Data\Mapper\ConversionGateway;
use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\Record\RecordState;
use ON\Data\ORM\Relation\RelationStateInterface;
use ON\Data\ORM\Relation\RelationStateStore;
use ON\Data\ORM\Relation\ToManyRelationState;
use ON\Data\ORM\Representation\State\RepresentationState;
use ON\Data\ORM\Representation\State\RepresentationStateStore;
use ON\Data\Schema\RelationLoadKnowledge;
use ON\Data\Schema\RepresentationRelationSchema;

final class RelationRepresentationSynchronizer
{
	public function __construct(private readonly ?ConversionGateway $gateway = null)
	{
	}

	/**
	 * @return list<RelationStateInterface>
	 */
	public function sync(
		RepresentationStateStore $reps,
		RelationStateStore $relations,
		?RepresentationStateStore $states = null,
		?RepresentationReader $operation = null,
	): array {
		$touched = [];
		$touchedIds = [];
		$states ??= $reps;
		$reader = $operation?->forGateway($this->gateway) ?? new RepresentationReader($this->gateway);

		foreach ($reps->getAll() as $representation => $state) {
			$objectReader = $reader->withObject($representation);
			foreach ($state->getSchema()->getRelations() as $relationSchema) {
				$owner = $state->requireRootRecord();
				if ($relationSchema->isMany()) {
					$this->syncMany($objectReader, $relationSchema, $owner, $relations, $states, $touched, $touchedIds);

					continue;
				}

				if ($relationSchema->isSingle()) {
					$this->syncOne($objectReader, $relationSchema, $owner, $relations, $states, $touched, $touchedIds);
				}
			}
		}

		return $touched;
	}

	/**
	 * @param list<RelationStateInterface> $touched
	 * @param array<int, true> $touchedIds
	 */
	private function syncMany(
		ObjectRepresentationReader $reader,
		RepresentationRelationSchema $relationSchema,
		RecordState $owner,
		RelationStateStore $relations,
		RepresentationStateStore $states,
		array &$touched,
		array &$touchedIds,
	): void {
		$relationName = $relationSchema->getRelationName();

		$result = $reader->readItems($relationSchema, $this->syncError(...));
		if ($result->isMissing() && $relationSchema->shouldSkipWhenMissing()) {
			return;
		}
		$items = $result->getValue();
		foreach ($items as $item) {
			$this->requireTrackedRepresentation($states, $item, $relationSchema->getPath());
		}

		$relation = $relations->getOrCreate(
			$owner,
			$relationName,
			RelationCardinality::MANY,
			$relationSchema->getRelatedSchema(),
		);
		$this->applyLoadKnowledge($relation, $relationSchema);
		$relation->syncFromItems($items);
		$this->touch($relation, $touched, $touchedIds);
	}

	private function applyLoadKnowledge(
		ToManyRelationState $relation,
		RepresentationRelationSchema $relationSchema,
	): void {
		match ($relationSchema->getLoadKnowledge()) {
			RelationLoadKnowledge::Full => $relation->markFullyLoaded(),
			RelationLoadKnowledge::Partial => $relation->markPartiallyLoaded(),
			RelationLoadKnowledge::Unknown => null,
		};
	}

	/**
	 * @param list<RelationStateInterface> $touched
	 * @param array<int, true> $touchedIds
	 */
	private function syncOne(
		ObjectRepresentationReader $reader,
		RepresentationRelationSchema $relationSchema,
		RecordState $owner,
		RelationStateStore $relations,
		RepresentationStateStore $states,
		array &$touched,
		array &$touchedIds,
	): void {
		$relationName = $relationSchema->getRelationName();

		$result = $reader->readTarget($relationSchema, $this->syncError(...));
		if ($result->isMissing() && $relationSchema->shouldSkipWhenMissing()) {
			return;
		}
		$target = $result->getValue();
		if ($target !== null) {
			$this->requireTrackedRepresentation($states, $target, $relationSchema->getPath());
		}

		$relation = $relations->getOrCreate(
			$owner,
			$relationName,
			RelationCardinality::SINGLE,
			$relationSchema->getRelatedSchema(),
		);
		$relation->set($target);
		$this->touch($relation, $touched, $touchedIds);
	}

	private function requireTrackedRepresentation(
		RepresentationStateStore $states,
		object $object,
		string $path,
	): RepresentationState {
		$tracked = $states->get($object);
		if ($tracked instanceof RepresentationState) {
			return $tracked;
		}

		throw new SyncException(sprintf(
			"Representation relation path '%s' references an object that is not tracked; adopt or track the related object before synchronization.",
			$path
		));
	}

	/**
	 * @param non-empty-string $message
	 */
	private function syncError(string $message): SyncException
	{
		return new SyncException($message);
	}

	/**
	 * @param list<RelationStateInterface> $touched
	 * @param array<int, true> $touchedIds
	 */
	private function touch(RelationStateInterface $change, array &$touched, array &$touchedIds): void
	{
		$id = spl_object_id($change);
		if (! array_key_exists($id, $touchedIds)) {
			$touchedIds[$id] = true;
			$touched[] = $change;
		}
	}
}
