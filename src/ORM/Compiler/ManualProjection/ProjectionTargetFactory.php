<?php

declare(strict_types=1);

namespace ON\Data\ORM\Compiler\ManualProjection;

/**
 * Creates and attaches manual projection targets for create(), existing(), and
 * tracked() without duplicating branch logic in Builder.
 *
 * Exists because Builder's lifecycle methods share the same root/path/relation
 * resolution paths; this class owns record resolution and relation attachment.
 */
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Key;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\Session;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationRelationCardinality;
use ON\Data\ORM\State\RepresentationState;

final class ProjectionTargetFactory
{
	public function __construct(
		private Session $session,
		private Builder $builder,
		private PathResolver $pathResolver,
		private RelationApplier $relationApplier,
		private RepresentationTracker $representationTracker,
	) {
	}

	public function resolvePath(object $owner, string $path): PathResolution
	{
		return $this->pathResolver->resolve($owner, $path);
	}

	/**
	 * @param array<string, mixed> $values
	 */
	public function createRoot(CollectionInterface $collection, array $values): RootTarget
	{
		$record = $this->session->trackRecord(RecordState::new($collection, $values));

		return new RootTarget($record);
	}

	/**
	 * @param array<string, mixed> $values
	 */
	public function createAtPath(PathResolution $path, array $values): Target
	{
		$collection = $this->pathResolver->collectionFromBinding($path->getRelatedBinding());
		$record = $this->session->trackRecord(RecordState::new($collection, $values));

		return $this->attachPathTarget(
			$path->getOwner(),
			$path->getOwnerObject(),
			$path->getRelationName(),
			$path->getCardinality(),
			$path->getRelatedBinding(),
			$record,
		);
	}

	/**
	 * @param array<string, mixed> $values
	 */
	public function createAtRelation(RelationRef $relation, array $values): Target
	{
		$owner = $relation->getOwner()->getTargetRecord();
		$record = $this->session->trackRecord(RecordState::new($relation->getDefinition()->getCollection(), $values));

		return $this->attachRelationTarget(
			$owner,
			$relation->getName(),
			$this->relationCardinality($relation),
			new RepresentationBinding(),
			$record,
			$this->representationTracker->trackAdapter($record),
		);
	}

	public function existingRoot(CollectionInterface $collection, Key|array $key, array $seedValues): RootTarget
	{
		return new RootTarget($this->recordForExisting($collection, $key, $seedValues));
	}

	public function existingAtPath(PathResolution $path, Key|array $key, array $seedValues): Target
	{
		$record = $this->recordForExisting($this->pathResolver->collectionFromBinding($path->getRelatedBinding()), $key, $seedValues);

		return $this->attachPathTarget(
			$path->getOwner(),
			$path->getOwnerObject(),
			$path->getRelationName(),
			$path->getCardinality(),
			$path->getRelatedBinding(),
			$record,
		);
	}

	public function existingAtRelation(RelationRef $relation, Key|array $key, array $seedValues): Target
	{
		$owner = $relation->getOwner()->getTargetRecord();
		$record = $this->recordForExisting($relation->getDefinition()->getCollection(), $key, $seedValues);

		return $this->attachRelationTarget(
			$owner,
			$relation->getName(),
			$this->relationCardinality($relation),
			new RepresentationBinding(),
			$record,
			$this->representationTracker->trackAdapter($record),
		);
	}

	public function trackedRoot(object $representation, CollectionInterface $collection): RootTarget
	{
		$state = $this->session->getRepresentations()->get($representation);
		if (! $state instanceof RepresentationState) {
			throw new SyncException('Cannot use tracked() for a manual projection source because the representation is not tracked.');
		}

		$matches = $this->representationTracker->recordsForCollection($state, $collection);
		if ($matches === []) {
			throw new StateException(sprintf(
				"Cannot use tracked() for collection '%s' because the representation has no matching tracked record state.",
				$collection->getName()
			));
		}

		if (count($matches) > 1) {
			throw new StateException(sprintf(
				"Cannot use tracked() for collection '%s' because the matching record state is ambiguous.",
				$collection->getName()
			));
		}

		return new RootTarget($matches[0]);
	}

	public function trackedAtPath(PathResolution $path, object $representation, ?object $target): Target
	{
		$target ??= $representation;
		$record = $this->representationTracker->singleRecordForTrackedTarget(
			$target,
			$this->pathResolver->collectionFromBinding($path->getRelatedBinding()),
			sprintf("Cannot use tracked() for relation '%s'", $path->getRelationName())
		);

		return $this->attachPathTarget(
			$path->getOwner(),
			$path->getOwnerObject(),
			$path->getRelationName(),
			$path->getCardinality(),
			$path->getRelatedBinding(),
			$record,
			$target,
		);
	}

	public function trackedAtRelation(RelationRef $relation, object $representation, ?object $target): Target
	{
		$owner = $relation->getOwner()->getTargetRecord();
		$target ??= $representation;
		$record = $this->representationTracker->singleRecordForTrackedTarget(
			$target,
			$relation->getDefinition()->getCollection(),
			sprintf("Cannot use tracked() for relation '%s'", implode('.', $relation->getPath()))
		);

		return $this->attachRelationTarget(
			$owner,
			$relation->getName(),
			$this->relationCardinality($relation),
			new RepresentationBinding(),
			$record,
			$target,
		);
	}

	private function attachPathTarget(
		RecordState $owner,
		object $ownerObject,
		string $relationName,
		RepresentationRelationCardinality $cardinality,
		RepresentationBinding $relatedBinding,
		RecordState $record,
		?object $explicitTarget = null,
	): Target {
		$objectShaped = $this->builder->getRepresentation() !== $ownerObject;
		$target = $this->resolvePathTargetObject($record, $relatedBinding, $ownerObject, $explicitTarget, $objectShaped);
		$this->relationApplier->applyTarget($owner, $relationName, $cardinality, $relatedBinding, $target);

		return new Target(
			$this->builder,
			$owner,
			$relationName,
			$cardinality,
			$relatedBinding,
			$record,
			$target,
			$objectShaped,
		);
	}

	private function attachRelationTarget(
		RecordState $owner,
		string $relationName,
		RepresentationRelationCardinality $cardinality,
		RepresentationBinding $relatedBinding,
		RecordState $record,
		object $target,
	): Target {
		$this->relationApplier->applyTarget($owner, $relationName, $cardinality, $relatedBinding, $target);

		return new Target(
			$this->builder,
			$owner,
			$relationName,
			$cardinality,
			$relatedBinding,
			$record,
			$target,
			false,
		);
	}

	private function resolvePathTargetObject(
		RecordState $record,
		RepresentationBinding $relatedBinding,
		object $ownerObject,
		?object $explicitTarget,
		bool $objectShaped,
	): object {
		if ($explicitTarget !== null) {
			$this->representationTracker->trackTarget($explicitTarget, $record, $relatedBinding);

			return $explicitTarget;
		}

		if ($objectShaped) {
			$this->representationTracker->trackTarget($this->builder->getRepresentation(), $record, $relatedBinding);

			return $this->builder->getRepresentation();
		}

		return $this->representationTracker->trackFlattenedAdapter($record, $relatedBinding, $ownerObject);
	}

	/**
	 * @param array<string, mixed> $values
	 */
	private function recordForExisting(CollectionInterface $collection, Key|array $key, array $values): RecordState
	{
		$key = $collection->getKey($key);
		$record = $this->session->getRecords()->getByKey($key);
		if ($record instanceof RecordState) {
			if ($record->isRemoved()) {
				throw new StateException(sprintf(
					"Cannot identify collection '%s' key '%s' because it is already tracked as removed.",
					$collection->getName(),
					$key->getDebugString()
				));
			}

			return $record;
		}

		$record = RecordState::clean($key, $values + $key->getValues());
		$this->session->trackRecord($record);

		return $record;
	}

	private function relationCardinality(RelationRef $relation): RepresentationRelationCardinality
	{
		return $relation->getDefinition()->getCardinality() === 'many'
			? RepresentationRelationCardinality::MANY
			: RepresentationRelationCardinality::ONE;
	}
}
