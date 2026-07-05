<?php

declare(strict_types=1);

namespace ON\Data\ORM\Binding;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Relation\RelationInterface;
use ON\Data\ORM\State\RecordFieldRef;
use ON\Data\ORM\State\RecordRelationRef;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationFieldBinding;
use ON\Data\ORM\State\RepresentationRelationBinding;
use ON\Data\ORM\State\RepresentationRelationCardinality;
use ON\Data\Query\Expression\AliasedExpression;
use ON\Data\Query\Expression\FieldRef;
use ON\Data\Query\Relation\RelationRef;
use ON\Data\Query\Relation\RelationSelection;
use ON\Data\Query\Selection\SelectionItem;
use ON\Data\Query\SelectQuery;

final class SelectQueryBindingCompiler
{
	public function compile(SelectQuery $query): RepresentationBinding
	{
		$collection = $query->getCollection();
		$binding = new RepresentationBinding();

		$this->compileRootScalarFields($binding, $query, $collection);
		$this->compileRelationSourcedFlatFields($binding, $query);
		$this->compileRelationSelections($binding, $query);

		return $binding;
	}

	private function compileRootScalarFields(
		RepresentationBinding $binding,
		SelectQuery $query,
		CollectionInterface $collection,
	): void {
		$explicitSelections = $query->getSelections()->getExplicit();
		$selectedFields = $this->getRootExplicitScalarSelections($query);

		if ($explicitSelections === []) {
			$this->addDefaultFields($binding, $collection);
		} else {
			$this->addSelectedFields($binding, $collection, $query, $selectedFields);
		}

		$this->addPrimaryKeyFields($binding, $collection);
	}

	/**
	 * @return list<SelectionItem>
	 */
	private function getRootExplicitScalarSelections(SelectQuery $query): array
	{
		$selections = [];

		foreach ($query->getSelections()->getExplicit() as $selection) {
			if ($this->resolveRootFieldRef($query, $selection) !== null) {
				$selections[] = $selection;
			}
		}

		return $selections;
	}

	private function resolveRootFieldRef(SelectQuery $query, SelectionItem $selection): ?FieldRef
	{
		$expression = $selection->getExpression();

		if ($expression instanceof AliasedExpression) {
			$expression = $expression->getExpression();
		}

		if (! $expression instanceof FieldRef) {
			return null;
		}

		if ($expression->getSource() !== $query) {
			return null;
		}

		return $expression;
	}

	/**
	 * @param list<SelectionItem> $selections
	 */
	private function addSelectedFields(
		RepresentationBinding $binding,
		CollectionInterface $collection,
		SelectQuery $query,
		array $selections,
	): void {
		foreach ($selections as $selection) {
			$expression = $selection->getExpression();
			$path = $selection->getSelectionKey();
			$fieldRef = $this->resolveRootFieldRef($query, $selection);

			if (! $fieldRef instanceof FieldRef) {
				continue;
			}

			if ($expression instanceof AliasedExpression) {
				$path = $expression->getAlias();
			}

			$this->addFieldBinding(
				$binding,
				new RepresentationFieldBinding(
					$path,
					RecordFieldRef::template($collection, $fieldRef->getName()),
					writable: true,
				),
			);
		}
	}

	private function addDefaultFields(RepresentationBinding $binding, CollectionInterface $collection): void
	{
		foreach ($collection->getFields() as $field) {
			$this->addFieldBinding(
				$binding,
				new RepresentationFieldBinding(
					$field->getName(),
					RecordFieldRef::template($collection, $field->getName()),
					writable: true,
				),
			);
		}
	}

	private function addPrimaryKeyFields(RepresentationBinding $binding, CollectionInterface $collection): void
	{
		foreach ($collection->getPrimaryKey() as $fieldName) {
			if ($this->hasFieldBindingFor($binding, $collection, $fieldName)) {
				continue;
			}

			$this->addFieldBinding(
				$binding,
				new RepresentationFieldBinding(
					$fieldName,
					RecordFieldRef::template($collection, $fieldName),
					writable: false,
				),
			);
		}
	}

	private function compileRelationSourcedFlatFields(RepresentationBinding $binding, SelectQuery $query): void
	{
		/** @var array<string, RelationRef> $relationRefsByPath */
		$relationRefsByPath = [];

		foreach ($query->getSelections()->getExplicit() as $selection) {
			$resolved = $this->resolveRelationSourcedFieldRef($query, $selection);

			if ($resolved === null) {
				continue;
			}

			$fieldRef = $resolved['fieldRef'];
			$relationRef = $resolved['relationRef'];
			$targetCollection = $resolved['targetCollection'];
			$expression = $selection->getExpression();
			$path = $selection->getSelectionKey();

			if ($expression instanceof AliasedExpression) {
				$path = $expression->getAlias();
			}

			$this->addFieldBinding(
				$binding,
				new RepresentationFieldBinding(
					$path,
					RecordFieldRef::template($targetCollection, $fieldRef->getName()),
					writable: true,
				),
			);

			$relationRefsByPath[json_encode($relationRef->getPath(), JSON_THROW_ON_ERROR)] = $relationRef;
		}

		foreach ($relationRefsByPath as $relationRef) {
			$this->ensureRelatedIdentityBindings($binding, $query, $relationRef);
		}
	}

	/**
	 * @return array{fieldRef: FieldRef, relationRef: RelationRef, targetCollection: CollectionInterface}|null
	 */
	private function resolveRelationSourcedFieldRef(SelectQuery $query, SelectionItem $selection): ?array
	{
		$expression = $selection->getExpression();

		if ($expression instanceof AliasedExpression) {
			$expression = $expression->getExpression();
		}

		if (! $expression instanceof FieldRef) {
			return null;
		}

		if ($this->resolveRootFieldRef($query, $selection) !== null) {
			return null;
		}

		$source = $expression->getSource();

		if (! $source instanceof RelationRef || $source->getQuery() !== $query) {
			return null;
		}

		return [
			'fieldRef' => $expression,
			'relationRef' => $source,
			'targetCollection' => $source->getCollection(),
		];
	}

	private function ensureRelatedIdentityBindings(
		RepresentationBinding $binding,
		SelectQuery $query,
		RelationRef $relationRef,
	): void {
		$targetCollection = $relationRef->getCollection();

		foreach ($targetCollection->getPrimaryKey() as $fieldName) {
			if ($this->hasFieldBindingFor($binding, $targetCollection, $fieldName)) {
				continue;
			}

			$fieldRef = $relationRef->field($fieldName);
			$query->getSelections()->ensureInternalField($fieldRef);

			$this->addFieldBinding(
				$binding,
				new RepresentationFieldBinding(
					$fieldRef->getSelectionKey(),
					RecordFieldRef::template($targetCollection, $fieldName),
					writable: false,
				),
			);
		}
	}

	private function compileRelationSelections(RepresentationBinding $rootBinding, SelectQuery $query): void
	{
		$relationSelections = $query->getRelationSelections()->getAll();

		usort(
			$relationSelections,
			static fn (RelationSelection $left, RelationSelection $right): int => count($left->getPath()) <=> count($right->getPath()),
		);

		/** @var array<string, RepresentationBinding> $bindingsByPath */
		$bindingsByPath = [];

		foreach ($relationSelections as $selection) {
			$path = $selection->getPath();
			$pathKey = json_encode($path, JSON_THROW_ON_ERROR);
			$parentPathKey = $selection->getParentPathKey();
			$parentBinding = $parentPathKey === null
				? $rootBinding
				: $bindingsByPath[$parentPathKey];
			$ownerCollection = $this->resolveOwnerCollection($query, $path);
			$relationName = $selection->getName();
			$relationDefinition = $selection->getRelationRef()->getDefinition();
			$relatedBinding = $this->compileRelationBinding($selection);

			$parentBinding->addRelation(new RepresentationRelationBinding(
				$relationName,
				RecordRelationRef::forCollection($ownerCollection, $relationName),
				$this->relationCardinality($relationDefinition),
				$relatedBinding,
				$this->isCollectionFullyLoaded($selection, $relationDefinition),
			));

			$bindingsByPath[$pathKey] = $relatedBinding;
		}
	}

	/**
	 * @param list<string> $path
	 */
	private function resolveOwnerCollection(SelectQuery $query, array $path): CollectionInterface
	{
		$collection = $query->getCollection();

		foreach (array_slice($path, 0, -1) as $segment) {
			$relation = $collection->getRelation($segment);

			if (! $relation instanceof RelationInterface) {
				break;
			}

			$collection = $relation->getCollection();
		}

		return $collection;
	}

	private function compileRelationBinding(RelationSelection $selection): RepresentationBinding
	{
		$targetCollection = $this->relationTargetCollection($selection->getRelationRef()->getDefinition());
		$binding = new RepresentationBinding();
		$explicitFields = $selection->getFields();

		if ($explicitFields !== null) {
			foreach ($explicitFields as $fieldName) {
				$this->addFieldBinding(
					$binding,
					new RepresentationFieldBinding(
						$fieldName,
						RecordFieldRef::template($targetCollection, $fieldName),
						writable: true,
					),
				);
			}

			$this->addPrimaryKeyFields($binding, $targetCollection);
		} else {
			$this->addDefaultFields($binding, $targetCollection);
		}

		return $binding;
	}

	private function relationTargetCollection(RelationInterface $relation): CollectionInterface
	{
		return $relation->getCollection();
	}

	private function relationCardinality(RelationInterface $relation): RepresentationRelationCardinality
	{
		return $relation->getCardinality() === 'many'
			? RepresentationRelationCardinality::MANY
			: RepresentationRelationCardinality::ONE;
	}

	private function isCollectionFullyLoaded(RelationSelection $selection, RelationInterface $relation): bool
	{
		if ($this->relationCardinality($relation) === RepresentationRelationCardinality::ONE) {
			return false;
		}

		if ($selection->getLimit() !== null || $selection->hasOffset()) {
			return false;
		}

		if ($selection->getConditions() !== []) {
			return false;
		}

		return $selection->isLoaded();
	}

	private function hasFieldBindingFor(
		RepresentationBinding $binding,
		CollectionInterface $collection,
		string $fieldName,
	): bool {
		foreach ($binding->getFields() as $fieldBinding) {
			if (
				$fieldBinding->getField()->getCollectionName() === $collection->getName()
				&& $fieldBinding->getField()->getFieldName() === $fieldName
			) {
				return true;
			}
		}

		return false;
	}

	private function addFieldBinding(RepresentationBinding $binding, RepresentationFieldBinding $fieldBinding): void
	{
		if ($binding->hasField($fieldBinding->getPath())) {
			return;
		}

		$binding->addField($fieldBinding);
	}
}
