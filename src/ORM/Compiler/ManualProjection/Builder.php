<?php

declare(strict_types=1);

namespace ON\Data\ORM\Compiler\ManualProjection;

use InvalidArgumentException;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Key;
use ON\Data\ORM\Compiler\ProjectionFieldShape;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\Session;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationBindingMerger;
use ON\Data\ORM\State\RepresentationRelationCardinality;
use ON\Data\ORM\State\RepresentationState;

final class Builder
{
	/** @var list<ProjectionFieldShape> */
	private array $propertyShapes = [];

	private ?CollectionInterface $pendingCollection = null;

	private ?PathResolution $pendingPath = null;

	public function __construct(
		private Session $session,
		private object $representation,
		private BindingCompiler $bindingCompiler = new BindingCompiler(),
		private ?PathResolver $pathResolver = null,
		private ?RelationApplier $relationApplier = null,
		private ?RepresentationTracker $representationTracker = null,
		private RepresentationBindingMerger $bindingMerger = new RepresentationBindingMerger(),
	) {
		$this->pathResolver ??= new PathResolver($this->session->getRepresentations());
		$this->relationApplier ??= new RelationApplier(
			$this->session->getToManyRelations(),
			$this->session->getToOneRelations()
		);
		$this->representationTracker ??= new RepresentationTracker(
			$this->session->getRepresentations(),
			$this->session->getRecords()
		);
	}

	public function from(CollectionInterface $collection): self
	{
		$this->clearPending();
		$this->pendingCollection = $collection;

		return $this;
	}

	public function fromPath(object $owner, string $path): self
	{
		$this->clearPending();
		$this->pendingPath = $this->pathResolver->resolve($owner, $path);

		return $this;
	}

	public function tracked(?RelationRef $relation = null, ?object $object = null): RootTarget|Target
	{
		if ($relation instanceof RelationRef) {
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

		if ($this->pendingCollection !== null) {
			$collection = $this->pendingCollection;
			$this->clearPending();

			return $this->trackedRootTarget($collection);
		}

		if ($this->pendingPath !== null) {
			$path = $this->pendingPath;
			$this->clearPending();
			$target = $object ?? $this->representation;
			$record = $this->representationTracker->singleRecordForTrackedTarget($target, $this->pathResolver->collectionFromBinding($path->getRelatedBinding()), sprintf(
				"Cannot use tracked() for relation '%s'",
				$path->getRelationName()
			));

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

		throw new InvalidArgumentException('Builder::tracked() requires from(), fromPath(), or a relation reference.');
	}

	/**
	 * @param array<string, mixed> $values
	 */
	public function create(RelationRef|array $relationOrValues = [], array $values = []): RootTarget|Target
	{
		if ($relationOrValues instanceof RelationRef) {
			$owner = $relationOrValues->getOwner()->getTargetRecord();
			$record = $this->session->trackRecord(RecordState::new($relationOrValues->getDefinition()->getCollection(), $values));

			return $this->attachRelationTarget(
				$owner,
				$relationOrValues->getName(),
				$this->relationCardinality($relationOrValues),
				new RepresentationBinding(),
				$record,
				$this->representationTracker->trackAdapter($record),
			);
		}

		if ($this->pendingCollection !== null) {
			$collection = $this->pendingCollection;
			$this->clearPending();
			$record = $this->session->trackRecord(RecordState::new($collection, $relationOrValues));

			return new RootTarget($record);
		}

		if ($this->pendingPath !== null) {
			$path = $this->pendingPath;
			$this->clearPending();
			$collection = $this->pathResolver->collectionFromBinding($path->getRelatedBinding());
			$record = $this->session->trackRecord(RecordState::new($collection, $relationOrValues));

			return $this->attachPathTarget(
				$path->getOwner(),
				$path->getOwnerObject(),
				$path->getRelationName(),
				$path->getCardinality(),
				$path->getRelatedBinding(),
				$record,
			);
		}

		throw new InvalidArgumentException('Builder::create() requires from(), fromPath(), or a relation reference.');
	}

	/**
	 * @param array<string, mixed> $values
	 */
	public function existing(RelationRef|Key|array $relationOrKey, Key|array|null $key = null, array $values = []): RootTarget|Target
	{
		if ($relationOrKey instanceof RelationRef) {
			if ($key === null) {
				throw new InvalidArgumentException('Builder::existing() requires a key when identifying a relation target.');
			}

			$owner = $relationOrKey->getOwner()->getTargetRecord();
			$record = $this->recordForExisting($relationOrKey->getDefinition()->getCollection(), $key, $values);

			return $this->attachRelationTarget(
				$owner,
				$relationOrKey->getName(),
				$this->relationCardinality($relationOrKey),
				new RepresentationBinding(),
				$record,
				$this->representationTracker->trackAdapter($record),
			);
		}

		if ($this->pendingCollection !== null) {
			$collection = $this->pendingCollection;
			$this->clearPending();
			$seedValues = $this->resolveExistingSeedValues($key, $values);
			$record = $this->recordForExisting($collection, $relationOrKey, $seedValues);

			return new RootTarget($record);
		}

		if ($this->pendingPath !== null) {
			$path = $this->pendingPath;
			$this->clearPending();
			$seedValues = $this->resolveExistingSeedValues($key, $values);
			$record = $this->recordForExisting($this->pathResolver->collectionFromBinding($path->getRelatedBinding()), $relationOrKey, $seedValues);

			return $this->attachPathTarget(
				$path->getOwner(),
				$path->getOwnerObject(),
				$path->getRelationName(),
				$path->getCardinality(),
				$path->getRelatedBinding(),
				$record,
			);
		}

		throw new InvalidArgumentException('Builder::existing() requires from(), fromPath(), or a relation reference.');
	}

	public function finalizeObjectShapedTarget(Target $target): object
	{
		return $target->getTargetObject();
	}

	public function properties(PropertyRef|AllProperties ...$items): self
	{
		if ($items === []) {
			throw new InvalidArgumentException('Builder::properties() requires at least one property declaration.');
		}

		foreach ($items as $item) {
			array_push($this->propertyShapes, ...$this->normalizePropertyDeclaration($item));
		}

		return $this;
	}

	public function end(): object
	{
		$manualBinding = $this->bindingCompiler->compile($this->propertyShapes);
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
		$this->propertyShapes = [];

		return $this->representation;
	}

	private function clearPending(): void
	{
		$this->pendingCollection = null;
		$this->pendingPath = null;
	}

	/**
	 * Root/path `existing($key, $values)` passes seed values as the second argument.
	 *
	 * @param array<string, mixed> $values
	 *
	 * @return array<string, mixed>
	 */
	private function resolveExistingSeedValues(Key|array|null $key, array $values): array
	{
		if ($values !== []) {
			return $values;
		}

		if (is_array($key)) {
			return $key;
		}

		if ($key instanceof Key) {
			return $key->getValues();
		}

		return [];
	}

	private function trackedRootTarget(CollectionInterface $collection): RootTarget
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

		return new RootTarget($matches[0]);
	}

	/**
	 * @return list<ProjectionFieldShape>
	 */
	private function normalizePropertyDeclaration(PropertyRef|AllProperties $item): array
	{
		if ($item instanceof AllProperties) {
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
	): Target {
		$objectShaped = $this->representation !== $ownerObject;
		$target = $this->resolvePathTargetObject($record, $relatedBinding, $ownerObject, $explicitTarget, $objectShaped);
		$this->relationApplier->applyTarget($owner, $relationName, $cardinality, $relatedBinding, $target);

		return new Target(
			$this,
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
			$this,
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

	private function relationCardinality(RelationRef $relation): RepresentationRelationCardinality
	{
		return $relation->getDefinition()->getCardinality() === 'many'
			? RepresentationRelationCardinality::MANY
			: RepresentationRelationCardinality::ONE;
	}
}
