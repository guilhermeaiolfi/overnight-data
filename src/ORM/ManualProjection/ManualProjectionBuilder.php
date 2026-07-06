<?php

declare(strict_types=1);

namespace ON\Data\ORM\ManualProjection;

use InvalidArgumentException;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Key;
use ON\Data\ORM\Binding\ProjectionBindingAssembler;
use ON\Data\ORM\Binding\ProjectionSelectionNormalizer;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\Session;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationBindingMerger;
use ON\Data\ORM\State\RepresentationRelationCardinality;
use ON\Data\ORM\State\RepresentationState;
use ON\Data\Query\Expression\AliasedExpression;
use ON\Data\Query\Expression\StarExpression;
use ON\Data\Query\Expression\ValueExpressionInterface;
use ON\Data\Query\QuerySourceInterface;
use ON\Data\Query\Relation\RelationRef;
use ON\Data\Query\Selection\SelectionList;
use ON\Data\Query\SelectQuery;

final class ManualProjectionBuilder
{
	/** @var list<ValueExpressionInterface|AliasedExpression|StarExpression> */
	private array $selections = [];

	public function __construct(
		private Session $session,
		private object $representation,
		private ProjectionSelectionNormalizer $selectionNormalizer = new ProjectionSelectionNormalizer(),
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
		return new ProjectionSourceDeclaration($this, new SelectQuery($collection));
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

	public function trackSource(SelectQuery $source): SelectQuery
	{
		$state = $this->session->getRepresentations()->get($this->representation);
		if (! $state instanceof RepresentationState) {
			throw new SyncException('Cannot use tracked() for a manual projection source because the representation is not tracked.');
		}

		$matches = $this->representationTracker->recordsForCollection($state, $source->getCollection());
		if ($matches === []) {
			throw new StateException(sprintf(
				"Cannot use tracked() for collection '%s' because the representation has no matching tracked record state.",
				$source->getCollection()->getName()
			));
		}

		if (count($matches) > 1) {
			throw new StateException(sprintf(
				"Cannot use tracked() for collection '%s' because the matching record state is ambiguous.",
				$source->getCollection()->getName()
			));
		}

		$this->rememberSource($source, $matches[0]);

		return $source;
	}

	/**
	 * @param array<string, mixed> $values
	 */
	public function createSource(SelectQuery $source, array $values = []): SelectQuery
	{
		$record = $this->session->trackRecord(RecordState::new($source->getCollection(), $values));
		$this->rememberSource($source, $record);

		return $source;
	}

	/**
	 * @param array<string, mixed> $values
	 */
	public function existingSource(SelectQuery $source, Key|array $key, array $values = []): SelectQuery
	{
		$record = $this->recordForExisting($source->getCollection(), $key, $values);
		$this->rememberSource($source, $record);

		return $source;
	}

	/**
	 * @param array<string, mixed> $values
	 */
	public function create(RelationRef $relation, array $values = []): RelationRef
	{
		$owner = $this->ownerRecordFor($relation);
		$record = $this->session->trackRecord(RecordState::new($relation->getCollection(), $values));
		$this->rememberSource($relation, $record);
		$this->applyRelationTarget(
			$owner,
			$relation->getName(),
			$this->relationCardinality($relation),
			new RepresentationBinding(),
			$this->representationTracker->trackAdapter($record)
		);

		return $relation;
	}

	/**
	 * @param array<string, mixed> $values
	 */
	public function existing(RelationRef $relation, Key|array $key, array $values = []): RelationRef
	{
		$owner = $this->ownerRecordFor($relation);
		$record = $this->recordForExisting($relation->getCollection(), $key, $values);
		$this->rememberSource($relation, $record);
		$this->applyRelationTarget(
			$owner,
			$relation->getName(),
			$this->relationCardinality($relation),
			new RepresentationBinding(),
			$this->representationTracker->trackAdapter($record)
		);

		return $relation;
	}

	public function tracked(RelationRef $relation, ?object $object = null): RelationRef
	{
		$owner = $this->ownerRecordFor($relation);
		$target = $object ?? $this->representation;
		$record = $this->representationTracker->singleRecordForTrackedTarget($target, $relation->getCollection(), sprintf(
			"Cannot use tracked() for relation '%s'",
			implode('.', $relation->getPath())
		));

		$this->rememberSource($relation, $record);
		$this->applyRelationTarget(
			$owner,
			$relation->getName(),
			$this->relationCardinality($relation),
			new RepresentationBinding(),
			$target
		);

		return $relation;
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

	public function rememberTargetSelectionSource(ManualProjectionTarget $target, SelectQuery $source): void
	{
		$this->rememberSource($source, $target->getTargetRecord());
	}

	public function finalizeObjectShapedTarget(ManualProjectionTarget $target): object
	{
		return $target->getTargetObject();
	}

	public function select(ValueExpressionInterface|AliasedExpression|StarExpression ...$expressions): self
	{
		if ($expressions === []) {
			throw new InvalidArgumentException('ManualProjectionBuilder::select() requires at least one expression.');
		}

		array_push($this->selections, ...$expressions);

		return $this;
	}

	public function end(): object
	{
		$manualBinding = new RepresentationBinding();
		if ($this->selections !== []) {
			$selectionList = new SelectionList();
			$selectionList->addExplicit($this->selections);
			$manualBinding = $this->bindingAssembler->assemble(
				$this->selectionNormalizer->normalizeSelections($selectionList->getExplicit(), ignoreUnsupported: false),
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

		return $this->representation;
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

		return new ManualProjectionTarget(
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

	private function ownerRecordFor(RelationRef $relation): RecordState
	{
		$source = $relation->getParentRelation() ?? $relation->getQuery();
		$owner = $this->sourceResolver->recordFor($source);
		if ($owner instanceof RecordState) {
			return $owner;
		}

		throw new StateException(sprintf(
			"Cannot create or identify relation '%s' because its owner source has no concrete record identity.",
			implode('.', $relation->getPath())
		));
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

	private function applyRelationTarget(
		RecordState $owner,
		string $relationName,
		RepresentationRelationCardinality $cardinality,
		RepresentationBinding $relatedBinding,
		object $target,
	): void {
		$this->relationApplier->applyTarget($owner, $relationName, $cardinality, $relatedBinding, $target);
	}

	private function rememberSource(QuerySourceInterface $source, RecordState $record): void
	{
		$this->sourceResolver->rememberSource($source, $record);
	}

	private function relationCardinality(RelationRef $relation): RepresentationRelationCardinality
	{
		return $relation->getDefinition()->getCardinality() === 'many'
			? RepresentationRelationCardinality::MANY
			: RepresentationRelationCardinality::ONE;
	}
}
