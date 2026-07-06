<?php

declare(strict_types=1);

namespace ON\Data\ORM\ManualProjection;

use InvalidArgumentException;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Key;
use ON\Data\ORM\Binding\SelectionProjectionCompiler;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\Relation\ToManyRelationState;
use ON\Data\ORM\Relation\ToOneRelationState;
use ON\Data\ORM\Session;
use ON\Data\ORM\State\RecordFieldRef;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationFieldBinding;
use ON\Data\ORM\State\RepresentationRelationBinding;
use ON\Data\ORM\State\RepresentationRelationCardinality;
use ON\Data\ORM\State\RepresentationState;
use ON\Data\Query\Expression\AliasedExpression;
use ON\Data\Query\Expression\StarExpression;
use ON\Data\Query\Expression\ValueExpressionInterface;
use ON\Data\Query\QuerySourceInterface;
use ON\Data\Query\Relation\RelationRef;
use ON\Data\Query\Selection\SelectionList;
use ON\Data\Query\SelectQuery;
use stdClass;

final class ManualProjectionBuilder
{
	/** @var list<ValueExpressionInterface|AliasedExpression|StarExpression> */
	private array $selections = [];

	public function __construct(
		private Session $session,
		private object $representation,
		private SelectionProjectionCompiler $selectionCompiler = new SelectionProjectionCompiler(),
		private ManualProjectionIdentityProvider $identityProvider = new ManualProjectionIdentityProvider(),
	) {
	}

	public function from(CollectionInterface $collection): ProjectionSourceDeclaration
	{
		return new ProjectionSourceDeclaration($this, new SelectQuery($collection));
	}

	public function fromPath(object $owner, string $path): ProjectionPathDeclaration
	{
		$ownerState = $this->session->getRepresentations()->get($owner);
		if (! $ownerState instanceof RepresentationState) {
			throw new SyncException('Cannot use fromPath() because the owner representation is not tracked.');
		}

		$relationBinding = $this->relationBindingFromPath($ownerState->getBinding(), $path);
		$relation = $relationBinding->getRelation();
		if (! $relation->hasState()) {
			throw new StateException(sprintf("Cannot use fromPath('%s') because the owner relation binding is not bound to a concrete record state.", $path));
		}

		return new ProjectionPathDeclaration(
			$this,
			$owner,
			$relation->getState(),
			$path,
			$relationBinding->getRelationName(),
			$relationBinding->getCardinality(),
			$relationBinding->getRelatedBinding()
		);
	}

	public function trackSource(SelectQuery $source): SelectQuery
	{
		$state = $this->session->getRepresentations()->get($this->representation);
		if (! $state instanceof RepresentationState) {
			throw new SyncException('Cannot use tracked() for a manual projection source because the representation is not tracked.');
		}

		$matches = $this->recordsForCollection($state, $source->getCollection());
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
			$this->trackAdapterRepresentation($record)
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
			$this->trackAdapterRepresentation($record)
		);

		return $relation;
	}

	public function tracked(RelationRef $relation, ?object $object = null): RelationRef
	{
		$owner = $this->ownerRecordFor($relation);
		$target = $object ?? $this->representation;
		$record = $this->singleRecordForTrackedTarget($target, $relation->getCollection(), sprintf(
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
	public function createPathSource(
		RecordState $owner,
		object $ownerObject,
		string $path,
		string $relationName,
		RepresentationRelationCardinality $cardinality,
		RepresentationBinding $relatedBinding,
		array $values = [],
	): ProjectionPathSource {
		$collection = $this->collectionFromBinding($relatedBinding);
		$record = $this->session->trackRecord(RecordState::new($collection, $values));

		return $this->pathSource($owner, $ownerObject, $path, $relationName, $cardinality, $relatedBinding, $record);
	}

	/**
	 * @param array<string, mixed> $values
	 */
	public function existingPathSource(
		RecordState $owner,
		object $ownerObject,
		string $path,
		string $relationName,
		RepresentationRelationCardinality $cardinality,
		RepresentationBinding $relatedBinding,
		Key|array $key,
		array $values = [],
	): ProjectionPathSource {
		$record = $this->recordForExisting($this->collectionFromBinding($relatedBinding), $key, $values);

		return $this->pathSource($owner, $ownerObject, $path, $relationName, $cardinality, $relatedBinding, $record);
	}

	public function trackedPathSource(
		RecordState $owner,
		object $ownerObject,
		string $path,
		string $relationName,
		RepresentationRelationCardinality $cardinality,
		RepresentationBinding $relatedBinding,
		?object $object = null,
	): ProjectionPathSource {
		$target = $object ?? $this->representation;
		$record = $this->singleRecordForTrackedTarget($target, $this->collectionFromBinding($relatedBinding), sprintf(
			"Cannot use tracked() for relation '%s'",
			$relationName
		));

		return $this->pathSource($owner, $ownerObject, $path, $relationName, $cardinality, $relatedBinding, $record, $target);
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
		$selectionList = new SelectionList();
		$selectionList->addExplicit($this->selections);
		$manualBinding = $this->selectionCompiler->compile(
			$selectionList->getExplicit(),
			$this->identityProvider,
			skipWhenMissing: true,
			ignoreUnsupported: false
		);
		$state = $this->session->getRepresentations()->get($this->representation);

		if ($state instanceof RepresentationState) {
			$binding = $this->mergeBindings($state->getBinding(), $manualBinding);
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
		$this->identityProvider->clear();

		return $this->representation;
	}

	private function ownerRecordFor(RelationRef $relation): RecordState
	{
		$source = $relation->getParentRelation() ?? $relation->getQuery();
		$owner = $this->identityProvider->recordFor($source);
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

	private function pathSource(
		RecordState $owner,
		object $ownerObject,
		string $path,
		string $relationName,
		RepresentationRelationCardinality $cardinality,
		RepresentationBinding $relatedBinding,
		RecordState $record,
		?object $target = null,
	): ProjectionPathSource {
		$source = new SelectQuery($record->getCollection());
		$this->rememberSource($source, $record);
		$target ??= $this->targetObjectForPath($record, $relatedBinding);
		$this->applyRelationTarget($owner, $relationName, $cardinality, $relatedBinding, $target);
		$this->mirrorRelationTarget($ownerObject, $path, $cardinality, $target);

		return new ProjectionPathSource($this, $source);
	}

	private function targetObjectForPath(RecordState $record, RepresentationBinding $relatedBinding): object
	{
		$target = $this->representation;
		$state = $this->session->getRepresentations()->get($target);
		if ($state instanceof RepresentationState) {
			$existing = $this->session->getRecords()->getFromRepresentation($state);
			if ($existing !== $record) {
				$target = new stdClass();
			}
		}

		$this->trackTargetRepresentation($target, $record, $relatedBinding);

		return $target;
	}

	private function trackAdapterRepresentation(RecordState $record): object
	{
		$object = new stdClass();
		$binding = new RepresentationBinding();
		foreach ($record->getCollection()->getPrimaryKey() as $fieldName) {
			if ($record->hasValue($fieldName)) {
				$object->{$fieldName} = $record->getValue($fieldName);
			}

			$binding->addField(new RepresentationFieldBinding($fieldName, RecordFieldRef::forState($record, $fieldName), writable: false));
		}

		$this->session->getRepresentations()->add($object, new RepresentationState($binding, [$record->getStateHash() => $record->getRevision()]));

		return $object;
	}

	private function trackTargetRepresentation(object $target, RecordState $record, RepresentationBinding $relatedBinding): void
	{
		$state = $this->session->getRepresentations()->get($target);
		if ($state instanceof RepresentationState) {
			return;
		}

		$binding = $relatedBinding->applyToRecordState($record, skipWhenMissing: true);
		$this->session->getRepresentations()->add($target, new RepresentationState($binding, [$record->getStateHash() => $record->getRevision()]));
	}

	private function applyRelationTarget(
		RecordState $owner,
		string $relationName,
		RepresentationRelationCardinality $cardinality,
		RepresentationBinding $relatedBinding,
		object $target,
	): void {
		if ($cardinality === RepresentationRelationCardinality::MANY) {
			$relation = $this->session->getToManyRelations()->get($owner, $relationName);
			if (! $relation instanceof ToManyRelationState) {
				$relation = new ToManyRelationState($owner, $relationName, $relatedBinding);
				$this->session->trackToManyRelation($relation);
			}
			$relation->add($target);

			return;
		}

		$relation = $this->session->getToOneRelations()->get($owner, $relationName);
		if (! $relation instanceof ToOneRelationState) {
			$relation = new ToOneRelationState($owner, $relationName, $relatedBinding);
			$this->session->trackToOneRelation($relation);
		}
		$relation->set($target);
	}

	private function mirrorRelationTarget(
		object $owner,
		string $path,
		RepresentationRelationCardinality $cardinality,
		object $target,
	): void {
		$segments = array_values(array_filter(explode('.', $path), static fn (string $segment): bool => $segment !== ''));
		if ($segments === []) {
			return;
		}

		$current = $owner;
		$last = array_pop($segments);
		foreach ($segments as $segment) {
			$value = $current->{$segment} ?? null;
			if (! is_object($value)) {
				return;
			}

			$current = $value;
		}

		if ($cardinality === RepresentationRelationCardinality::MANY) {
			$items = $current->{$last} ?? [];
			if (! is_array($items)) {
				$items = [];
			}

			foreach ($items as $item) {
				if ($item === $target) {
					$current->{$last} = $items;

					return;
				}
			}

			$items[] = $target;
			$current->{$last} = $items;

			return;
		}

		$current->{$last} = $target;
	}

	private function relationBindingFromPath(RepresentationBinding $binding, string $path): RepresentationRelationBinding
	{
		$segments = array_values(array_filter(explode('.', $path), static fn (string $segment): bool => $segment !== ''));
		if ($segments === []) {
			throw new InvalidArgumentException('ManualProjectionBuilder::fromPath() requires a non-empty path.');
		}

		$current = $binding;
		$relation = null;
		foreach ($segments as $segment) {
			$relation = $current->getRelation($segment);
			$current = $relation->getRelatedBinding();
		}

		return $relation;
	}

	private function collectionFromBinding(RepresentationBinding $binding): CollectionInterface
	{
		foreach ($binding->getFields() as $fieldBinding) {
			return $fieldBinding->getField()->getCollection();
		}

		foreach ($binding->getRelations() as $relationBinding) {
			return $relationBinding->getRelation()->getCollection();
		}

		throw new StateException('Cannot resolve relation target collection from an empty related binding.');
	}

	/**
	 * @return list<RecordState>
	 */
	private function recordsForCollection(RepresentationState $state, CollectionInterface $collection): array
	{
		$records = [];
		foreach ($state->getBinding()->getFields() as $fieldBinding) {
			$field = $fieldBinding->getField();
			if ($field->hasState() && $field->getCollectionName() === $collection->getName()) {
				$record = $field->getState();
				$records[$record->getStateHash()] = $record;
			}
		}

		foreach ($state->getBinding()->getRelations() as $relationBinding) {
			$relation = $relationBinding->getRelation();
			if ($relation->hasState() && $relation->getCollectionName() === $collection->getName()) {
				$record = $relation->getState();
				$records[$record->getStateHash()] = $record;
			}
		}

		return array_values($records);
	}

	private function singleRecordForTrackedTarget(object $target, CollectionInterface $collection, string $prefix): RecordState
	{
		$state = $this->session->getRepresentations()->get($target);
		if (! $state instanceof RepresentationState) {
			throw new SyncException($prefix . ' because the target representation is not tracked.');
		}

		$matches = $this->recordsForCollection($state, $collection);
		if ($matches === []) {
			throw new StateException($prefix . ' because the target has no matching tracked record state.');
		}

		if (count($matches) > 1) {
			throw new StateException($prefix . ' because the matching target record state is ambiguous.');
		}

		return $matches[0];
	}

	private function mergeBindings(RepresentationBinding $existing, RepresentationBinding $manual): RepresentationBinding
	{
		$merged = new RepresentationBinding();
		foreach ($existing->getFields() as $field) {
			$merged->addField($field);
		}
		foreach ($existing->getExpressions() as $expression) {
			$merged->addExpression($expression);
		}
		foreach ($existing->getRelations() as $relation) {
			$merged->addRelation($relation);
		}

		foreach ($manual->getFields() as $field) {
			if ($merged->hasPath($field->getPath())) {
				throw new StateException(sprintf("Manual projection path '%s' conflicts with an existing representation binding path.", $field->getPath()));
			}
			$merged->addField($field);
		}

		foreach ($manual->getRelations() as $relation) {
			if ($merged->hasPath($relation->getPath())) {
				continue;
			}
			$merged->addRelation($relation);
		}

		return $merged;
	}

	private function rememberSource(QuerySourceInterface $source, RecordState $record): void
	{
		$this->identityProvider->rememberSource($source, $record);
	}

	private function relationCardinality(RelationRef $relation): RepresentationRelationCardinality
	{
		return $relation->getDefinition()->getCardinality() === 'many'
			? RepresentationRelationCardinality::MANY
			: RepresentationRelationCardinality::ONE;
	}
}
