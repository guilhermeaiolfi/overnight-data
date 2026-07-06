<?php

declare(strict_types=1);

namespace ON\Data\ORM\ManualProjection;

use InvalidArgumentException;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Key;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Exception\SyncException;
use ON\Data\ORM\Relation\ToManyRelationState;
use ON\Data\ORM\Relation\ToOneRelationState;
use ON\Data\ORM\Session;
use ON\Data\ORM\State\RecordFieldRef;
use ON\Data\ORM\State\RecordRelationRef;
use ON\Data\ORM\State\RecordState;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationFieldBinding;
use ON\Data\ORM\State\RepresentationRelationBinding;
use ON\Data\ORM\State\RepresentationRelationCardinality;
use ON\Data\ORM\State\RepresentationState;
use ON\Data\Query\Expression\AliasedExpression;
use ON\Data\Query\Expression\FieldRef;
use ON\Data\Query\Expression\StarExpression;
use ON\Data\Query\Expression\ValueExpressionInterface;
use ON\Data\Query\QuerySourceInterface;
use ON\Data\Query\Relation\RelationRef;
use ON\Data\Query\SelectQuery;
use stdClass;

final class ManualProjectionBuilder
{
	/** @var list<ValueExpressionInterface|AliasedExpression|StarExpression> */
	private array $selections = [];

	/** @var array<int, RecordState> */
	private array $recordsBySourceId = [];

	/** @var list<array{owner: RecordState, relation: RelationRef, target: object}> */
	private array $relationIntents = [];

	public function __construct(
		private Session $session,
		private object $representation,
	) {
	}

	public function from(CollectionInterface $collection): ProjectionSourceDeclaration
	{
		return new ProjectionSourceDeclaration($this, new SelectQuery($collection));
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

		$this->recordsBySourceId[spl_object_id($source)] = $matches[0];

		return $source;
	}

	/**
	 * @param array<string, mixed> $values
	 */
	public function createSource(SelectQuery $source, array $values = []): SelectQuery
	{
		$record = $this->session->trackRecord(RecordState::new($source->getCollection(), $values));
		$this->recordsBySourceId[spl_object_id($source)] = $record;

		return $source;
	}

	/**
	 * @param array<string, mixed> $values
	 */
	public function existingSource(SelectQuery $source, Key|array $key, array $values = []): SelectQuery
	{
		$record = $this->recordForExisting($source->getCollection(), $key, $values);
		$this->recordsBySourceId[spl_object_id($source)] = $record;

		return $source;
	}

	/**
	 * @param array<string, mixed> $values
	 */
	public function create(RelationRef $relation, array $values = []): RelationRef
	{
		$owner = $this->ownerRecordFor($relation);
		$record = $this->session->trackRecord(RecordState::new($relation->getCollection(), $values));
		$this->recordsBySourceId[spl_object_id($relation)] = $record;
		$this->relationIntents[] = [
			'owner' => $owner,
			'relation' => $relation,
			'target' => $this->trackInternalRepresentation($record),
		];

		return $relation;
	}

	/**
	 * @param array<string, mixed> $values
	 */
	public function existing(RelationRef $relation, Key|array $key, array $values = []): RelationRef
	{
		$owner = $this->ownerRecordFor($relation);
		$record = $this->recordForExisting($relation->getCollection(), $key, $values);
		$this->recordsBySourceId[spl_object_id($relation)] = $record;
		$this->relationIntents[] = [
			'owner' => $owner,
			'relation' => $relation,
			'target' => $this->trackInternalRepresentation($record),
		];

		return $relation;
	}

	public function tracked(RelationRef $relation, ?object $object = null): RelationRef
	{
		$owner = $this->ownerRecordFor($relation);
		$target = $object ?? $this->representation;
		$state = $this->session->getRepresentations()->get($target);
		if (! $state instanceof RepresentationState) {
			throw new SyncException('Cannot use tracked() for a manual projection relation because the target representation is not tracked.');
		}

		$matches = $this->recordsForCollection($state, $relation->getCollection());
		if ($matches === []) {
			throw new StateException(sprintf(
				"Cannot use tracked() for relation '%s' because the target has no matching tracked record state.",
				implode('.', $relation->getPath())
			));
		}

		if (count($matches) > 1) {
			throw new StateException(sprintf(
				"Cannot use tracked() for relation '%s' because the matching target record state is ambiguous.",
				implode('.', $relation->getPath())
			));
		}

		$this->recordsBySourceId[spl_object_id($relation)] = $matches[0];
		$this->relationIntents[] = [
			'owner' => $owner,
			'relation' => $relation,
			'target' => $target,
		];

		return $relation;
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
		$manualBinding = $this->compileBinding();
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
		$this->applyRelationIntents();

		return $this->representation;
	}

	private function compileBinding(): RepresentationBinding
	{
		$binding = new RepresentationBinding();

		foreach ($this->selections as $expression) {
			$fieldRef = $this->fieldRefFrom($expression);
			$path = $this->selectionPath($expression, $fieldRef);
			$record = $this->recordForSource($fieldRef->getSource(), $fieldRef);

			$binding->addField(new RepresentationFieldBinding(
				$path,
				RecordFieldRef::forState($record, $fieldRef->getName()),
				writable: true,
				skipWhenMissing: true
			));
		}

		foreach ($this->relationIntents as $intent) {
			$relation = $intent['relation'];
			$binding->addRelation(new RepresentationRelationBinding(
				implode('.', $relation->getPath()),
				RecordRelationRef::forState($intent['owner'], $relation->getName()),
				$this->relationCardinality($relation),
				new RepresentationBinding(),
				skipWhenMissing: true
			));
		}

		return $binding;
	}

	private function fieldRefFrom(ValueExpressionInterface|AliasedExpression|StarExpression $expression): FieldRef
	{
		if ($expression instanceof AliasedExpression) {
			$expression = $expression->getExpression();
		}

		if (! $expression instanceof FieldRef) {
			throw new InvalidArgumentException('Manual mutable projections only support FieldRef selections in this release.');
		}

		return $expression;
	}

	private function selectionPath(ValueExpressionInterface|AliasedExpression|StarExpression $expression, FieldRef $fieldRef): string
	{
		if ($expression instanceof AliasedExpression) {
			return $expression->getAlias();
		}

		return $fieldRef->getSelectionKey();
	}

	private function recordForSource(QuerySourceInterface $source, FieldRef $fieldRef): RecordState
	{
		$record = $this->recordsBySourceId[spl_object_id($source)] ?? null;
		if ($record instanceof RecordState) {
			return $record;
		}

		if ($source instanceof RelationRef && $source->getDefinition()->getCardinality() === 'many') {
			throw new StateException(sprintf(
				"Cannot select MANY relation field '%s' without first creating or identifying one concrete relation item.",
				implode('.', $fieldRef->getPath())
			));
		}

		throw new StateException(sprintf(
			"Cannot select field '%s' because its projection source has no concrete record identity.",
			implode('.', $fieldRef->getPath())
		));
	}

	private function ownerRecordFor(RelationRef $relation): RecordState
	{
		$source = $relation->getParentRelation() ?? $relation->getQuery();
		$owner = $this->recordsBySourceId[spl_object_id($source)] ?? null;
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

	private function trackInternalRepresentation(RecordState $record): object
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

	private function applyRelationIntents(): void
	{
		foreach ($this->relationIntents as $intent) {
			$owner = $intent['owner'];
			$relationRef = $intent['relation'];
			$relationName = $relationRef->getName();
			$target = $intent['target'];

			if ($this->relationCardinality($relationRef) === RepresentationRelationCardinality::MANY) {
				$relation = $this->session->getToManyRelations()->get($owner, $relationName);
				if (! $relation instanceof ToManyRelationState) {
					$relation = new ToManyRelationState($owner, $relationName, new RepresentationBinding());
					$this->session->trackToManyRelation($relation);
				}
				$relation->add($target);

				continue;
			}

			$relation = $this->session->getToOneRelations()->get($owner, $relationName);
			if (! $relation instanceof ToOneRelationState) {
				$relation = new ToOneRelationState($owner, $relationName, new RepresentationBinding());
				$this->session->trackToOneRelation($relation);
			}
			$relation->set($target);
		}
	}

	private function relationCardinality(RelationRef $relation): RepresentationRelationCardinality
	{
		return $relation->getDefinition()->getCardinality() === 'many'
			? RepresentationRelationCardinality::MANY
			: RepresentationRelationCardinality::ONE;
	}
}
