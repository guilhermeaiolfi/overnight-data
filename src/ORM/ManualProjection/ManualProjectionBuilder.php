<?php

declare(strict_types=1);

namespace ON\Data\ORM\ManualProjection;

use InvalidArgumentException;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Key;
use ON\Data\ORM\Binding\ProjectionBindingAssembler;
use ON\Data\ORM\Binding\ProjectionFieldShape;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\Session;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationBindingMerger;
use ON\Data\ORM\State\RepresentationRelationCardinality;
use ON\Data\ORM\State\RepresentationState;

final class ManualProjectionBuilder
{
	/** @var list<ProjectionFieldShape> */
	private array $propertyShapes = [];

	public function __construct(
		private Session $session,
		private object $representation,
		private ProjectionBindingAssembler $bindingAssembler = new ProjectionBindingAssembler(),
		private ManualProjectionSourceResolver $sourceResolver = new ManualProjectionSourceResolver(),
		private ?ManualProjectionPathResolver $pathResolver = null,
		private ?ManualProjectionRelationApplier $relationApplier = null,
		private ?ManualProjectionRepresentationTracker $representationTracker = null,
		private RepresentationBindingMerger $bindingMerger = new RepresentationBindingMerger(),
	) {
		$this->pathResolver ??= new ManualProjectionPathResolver($this->session->getRepresentations());
		$this->relationApplier ??= new ManualProjectionRelationApplier(
			$this->session->getToManyRelations(),
			$this->session->getToOneRelations()
		);
		$this->representationTracker ??= new ManualProjectionRepresentationTracker(
			$this->session->getRepresentations(),
			$this->session->getRecords()
		);
	}

	public function from(CollectionInterface $collection): ProjectionSourceDeclaration
	{
		return new ProjectionSourceDeclaration($this, $collection);
	}

	public function fromPath(object $owner, string $path): ProjectionPathDeclaration
	{
		$resolution = $this->pathResolver->resolve($owner, $path);

		return new ProjectionPathDeclaration(
			$this,
			$resolution->getOwnerObject(),
			$resolution->getOwner(),
			$resolution->getPath(),
			$resolution->getRelationName(),
			$resolution->getCardinality(),
			$resolution->getRelatedBinding()
		);
	}

	public function trackSource(CollectionInterface $collection): ManualProjectionRootTarget
	{
		$state = $this->session->getRepresentations()->get($this->representation);
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

		return $this->rootTargetFor($matches[0]);
	}

	/**
	 * @param array<string, mixed> $values
	 */
	public function createSource(CollectionInterface $collection, array $values = []): ManualProjectionRootTarget
	{
		$record = $this->session->trackRecord(RecordState::new($collection, $values));

		return $this->rootTargetFor($record);
	}

	/**
	 * @param array<string, mixed> $values
	 */
	public function existingSource(CollectionInterface $collection, Key|array $key, array $values = []): ManualProjectionRootTarget
	{
		$record = $this->recordForExisting($collection, $key, $values);

		return $this->rootTargetFor($record);
	}

	/**
	 * @param array<string, mixed> $values
	 */
	public function create(ManualProjectionRelationRef $relation, array $values = []): ManualProjectionTarget
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

	/**
	 * @param array<string, mixed> $values
	 */
	public function existing(ManualProjectionRelationRef $relation, Key|array $key, array $values = []): ManualProjectionTarget
	{
		$owner = $relation->getOwner()->getTargetRecord();
		$record = $this->recordForExisting($relation->getDefinition()->getCollection(), $key, $values);

		return $this->attachRelationTarget(
			$owner,
			$relation->getName(),
			$this->relationCardinality($relation),
			new RepresentationBinding(),
			$record,
			$this->representationTracker->trackAdapter($record),
		);
	}

	public function tracked(ManualProjectionRelationRef $relation, ?object $object = null): ManualProjectionTarget
	{
		$owner = $relation->getOwner()->getTargetRecord();
		$target = $object ?? $this->representation;
		$record = $this->representationTracker->singleRecordForTrackedTarget($target, $relation->getDefinition()->getCollection(), sprintf(
			"Cannot use tracked() for relation '%s'",
			implode('.', $relation->getPath())
		));

		return $this->attachRelationTarget(
			$owner,
			$relation->getName(),
			$this->relationCardinality($relation),
			new RepresentationBinding(),
			$record,
			$target,
		);
	}

	/**
	 * @param array<string, mixed> $values
	 */
	public function createPathTarget(
		RecordState $owner,
		object $ownerObject,
		string $relationName,
		RepresentationRelationCardinality $cardinality,
		RepresentationBinding $relatedBinding,
		array $values = [],
	): ManualProjectionTarget {
		$collection = $this->pathResolver->collectionFromBinding($relatedBinding);
		$record = $this->session->trackRecord(RecordState::new($collection, $values));

		return $this->attachPathTarget($owner, $ownerObject, $relationName, $cardinality, $relatedBinding, $record);
	}

	/**
	 * @param array<string, mixed> $values
	 */
	public function existingPathTarget(
		RecordState $owner,
		object $ownerObject,
		string $relationName,
		RepresentationRelationCardinality $cardinality,
		RepresentationBinding $relatedBinding,
		Key|array $key,
		array $values = [],
	): ManualProjectionTarget {
		$record = $this->recordForExisting($this->pathResolver->collectionFromBinding($relatedBinding), $key, $values);

		return $this->attachPathTarget($owner, $ownerObject, $relationName, $cardinality, $relatedBinding, $record);
	}

	public function trackedPathTarget(
		RecordState $owner,
		object $ownerObject,
		string $relationName,
		RepresentationRelationCardinality $cardinality,
		RepresentationBinding $relatedBinding,
		?object $object = null,
	): ManualProjectionTarget {
		$target = $object ?? $this->representation;
		$record = $this->representationTracker->singleRecordForTrackedTarget($target, $this->pathResolver->collectionFromBinding($relatedBinding), sprintf(
			"Cannot use tracked() for relation '%s'",
			$relationName
		));

		return $this->attachPathTarget($owner, $ownerObject, $relationName, $cardinality, $relatedBinding, $record, $target);
	}

	public function finalizeObjectShapedTarget(ManualProjectionTarget $target): object
	{
		return $target->getTargetObject();
	}

	public function properties(ManualProjectionPropertyRef|ManualProjectionAllProperties ...$items): self
	{
		if ($items === []) {
			throw new InvalidArgumentException('ManualProjectionBuilder::properties() requires at least one property declaration.');
		}

		foreach ($items as $item) {
			array_push($this->propertyShapes, ...$this->normalizePropertyDeclaration($item));
		}

		return $this;
	}

	public function end(): object
	{
		$manualBinding = new RepresentationBinding();
		if ($this->propertyShapes !== []) {
			$manualBinding = $this->bindingAssembler->assemble(
				$this->propertyShapes,
				$this->sourceResolver,
				skipWhenMissing: true,
			);
		}
		$state = $this->session->getRepresentations()->get($this->representation);

		if ($state instanceof RepresentationState) {
			$binding = $this->bindingMerger->mergeManualOverlay($state->getBinding(), $manualBinding);
			$baselineRevisions = $state->getBaselineRevisions();
		} else {
			$binding = $manualBinding;
			$baselineRevisions = [];
		}

		foreach ($binding->getFields() as $fieldBinding) {
			$field = $fieldBinding->getField();
			if ($field->hasState()) {
				$baselineRevisions[$field->getRecordHash()] ??= $field->getState()->getRevision();
			}
		}

		foreach ($binding->getRelations() as $relationBinding) {
			$relation = $relationBinding->getRelation();
			if ($relation->hasState()) {
				$baselineRevisions[$relation->getRecordHash()] ??= $relation->getState()->getRevision();
			}
		}

		if ($state instanceof RepresentationState) {
			$this->session->getRepresentations()->remove($this->representation);
		}

		$this->session->getRepresentations()->add($this->representation, new RepresentationState($binding, $baselineRevisions));
		$this->sourceResolver->clear();
		$this->propertyShapes = [];

		return $this->representation;
	}

	private function rootTargetFor(RecordState $record): ManualProjectionRootTarget
	{
		$target = new ManualProjectionRootTarget($record);
		$this->sourceResolver->rememberSource($target, $record);

		return $target;
	}

	/**
	 * @return list<ProjectionFieldShape>
	 */
	private function normalizePropertyDeclaration(ManualProjectionPropertyRef|ManualProjectionAllProperties $item): array
	{
		if ($item instanceof ManualProjectionAllProperties) {
			$shapes = [];
			foreach ($item->getSource()->getTargetRecord()->getCollection()->getFields() as $field) {
				$shapes[] = new ProjectionFieldShape(
					$field->getName(),
					$item->getSource(),
					$field->getName(),
				);
			}

			return $shapes;
		}

		return [
			new ProjectionFieldShape(
				$item->getPublicPath(),
				$item->getSource(),
				$item->getFieldName(),
			),
		];
	}

	private function attachPathTarget(
		RecordState $owner,
		object $ownerObject,
		string $relationName,
		RepresentationRelationCardinality $cardinality,
		RepresentationBinding $relatedBinding,
		RecordState $record,
		?object $explicitTarget = null,
	): ManualProjectionTarget {
		$objectShaped = $this->representation !== $ownerObject;
		$target = $this->resolvePathTargetObject($record, $relatedBinding, $ownerObject, $explicitTarget, $objectShaped);
		$this->relationApplier->applyTarget($owner, $relationName, $cardinality, $relatedBinding, $target);

		$manualTarget = new ManualProjectionTarget(
			$this,
			$owner,
			$relationName,
			$cardinality,
			$relatedBinding,
			$record,
			$target,
			$objectShaped,
		);
		$this->sourceResolver->rememberSource($manualTarget, $record);

		return $manualTarget;
	}

	private function attachRelationTarget(
		RecordState $owner,
		string $relationName,
		RepresentationRelationCardinality $cardinality,
		RepresentationBinding $relatedBinding,
		RecordState $record,
		object $target,
	): ManualProjectionTarget {
		$this->relationApplier->applyTarget($owner, $relationName, $cardinality, $relatedBinding, $target);

		$manualTarget = new ManualProjectionTarget(
			$this,
			$owner,
			$relationName,
			$cardinality,
			$relatedBinding,
			$record,
			$target,
			false,
		);
		$this->sourceResolver->rememberSource($manualTarget, $record);

		return $manualTarget;
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
			$this->representationTracker->trackTarget($this->representation, $record, $relatedBinding);

			return $this->representation;
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

	private function relationCardinality(ManualProjectionRelationRef $relation): RepresentationRelationCardinality
	{
		return $relation->getDefinition()->getCardinality() === 'many'
			? RepresentationRelationCardinality::MANY
			: RepresentationRelationCardinality::ONE;
	}
}
