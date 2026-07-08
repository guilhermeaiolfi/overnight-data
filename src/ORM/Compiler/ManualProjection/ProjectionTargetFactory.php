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
use ON\Data\ORM\Relation\ToManyRelationState;
use ON\Data\ORM\Relation\ToOneRelationState;
use ON\Data\ORM\Session;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RepresentationSchema;
use ON\Data\Definition\Relation\RelationCardinality;
use ON\Data\ORM\State\RepresentationState;

final class ProjectionTargetFactory
{
	public function __construct(
		private Session $session,
		private object $rootRepresentation,
		private PathResolver $pathResolver,
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
		$collection = $path->getRelatedSchema()->getCollection();
		$record = $this->session->trackRecord(RecordState::new($collection, $values));

		return $this->attachPathTarget(
			$path->getOwner(),
			$path->getOwnerObject(),
			$path->getRelationName(),
			$path->getCardinality(),
			$path->getRelatedSchema(),
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
			new RepresentationSchema($relation->getDefinition()->getCollection()),
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
		$record = $this->recordForExisting($path->getRelatedSchema()->getCollection(), $key, $seedValues);

		return $this->attachPathTarget(
			$path->getOwner(),
			$path->getOwnerObject(),
			$path->getRelationName(),
			$path->getCardinality(),
			$path->getRelatedSchema(),
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
			new RepresentationSchema($relation->getDefinition()->getCollection()),
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
			$path->getRelatedSchema()->getCollection(),
			sprintf("Cannot use tracked() for relation '%s'", $path->getRelationName())
		);

		return $this->attachPathTarget(
			$path->getOwner(),
			$path->getOwnerObject(),
			$path->getRelationName(),
			$path->getCardinality(),
			$path->getRelatedSchema(),
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
			new RepresentationSchema($relation->getDefinition()->getCollection()),
			$record,
			$target,
		);
	}

	private function attachPathTarget(
		RecordState $owner,
		object $ownerObject,
		string $relationName,
		RelationCardinality $cardinality,
		RepresentationSchema $relatedSchema,
		RecordState $record,
		?object $explicitTarget = null,
	): Target {
		$objectShaped = $this->rootRepresentation !== $ownerObject;
		$target = $this->resolvePathTargetObject($record, $relatedSchema, $ownerObject, $explicitTarget, $objectShaped);
		$this->applyRelationTarget($owner, $relationName, $cardinality, $relatedSchema, $target);

		return new Target(
			$owner,
			$relationName,
			$cardinality,
			$relatedSchema,
			$record,
			$target,
			$objectShaped,
		);
	}

	private function attachRelationTarget(
		RecordState $owner,
		string $relationName,
		RelationCardinality $cardinality,
		RepresentationSchema $relatedSchema,
		RecordState $record,
		object $target,
	): Target {
		$this->applyRelationTarget($owner, $relationName, $cardinality, $relatedSchema, $target);

		return new Target(
			$owner,
			$relationName,
			$cardinality,
			$relatedSchema,
			$record,
			$target,
			false,
		);
	}

	private function resolvePathTargetObject(
		RecordState $record,
		RepresentationSchema $relatedSchema,
		object $ownerObject,
		?object $explicitTarget,
		bool $objectShaped,
	): object {
		if ($explicitTarget !== null) {
			$this->representationTracker->trackTarget($explicitTarget, $record, $relatedSchema);

			return $explicitTarget;
		}

		if ($objectShaped) {
			$this->representationTracker->trackTarget($this->rootRepresentation, $record, $relatedSchema);

			return $this->rootRepresentation;
		}

		return $this->representationTracker->trackFlattenedAdapter($record, $relatedSchema, $ownerObject);
	}

	private function applyRelationTarget(
		RecordState $owner,
		string $relationName,
		RelationCardinality $cardinality,
		RepresentationSchema $relatedSchema,
		object $target,
	): void {
		if ($cardinality->isMany()) {
			$this->applyToManyRelationTarget($owner, $relationName, $relatedSchema, $target);

			return;
		}

		$this->applyToOneRelationTarget($owner, $relationName, $relatedSchema, $target);
	}

	private function applyToManyRelationTarget(
		RecordState $owner,
		string $relationName,
		RepresentationSchema $relatedSchema,
		object $target,
	): void {
		$relation = $this->session->getToManyRelations()->get($owner, $relationName);
		if (! $relation instanceof ToManyRelationState) {
			$relation = new ToManyRelationState($owner, $relationName, $relatedSchema);
			$this->session->getToManyRelations()->add($relation);
		}

		$relation->add($target);
	}

	private function applyToOneRelationTarget(
		RecordState $owner,
		string $relationName,
		RepresentationSchema $relatedSchema,
		object $target,
	): void {
		$relation = $this->session->getToOneRelations()->get($owner, $relationName);
		if (! $relation instanceof ToOneRelationState) {
			$relation = new ToOneRelationState($owner, $relationName, $relatedSchema);
			$this->session->getToOneRelations()->add($relation);
		}

		$relation->set($target);
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

	private function relationCardinality(RelationRef $relation): RelationCardinality
	{
		return $relation->getDefinition()->getCardinality();
	}
}
