<?php

declare(strict_types=1);

namespace ON\Data\ORM\Compiler\SelectQuery;

/**
 * Compiles a SelectQuery selection graph into a RepresentationBinding plus
 * hidden identity metadata for flat mutable projection adoption.
 *
 * Exists as the query-side compiler: it normalizes selections, assembles scalar
 * field bindings, plans relation branches, injects internal PK selections, and
 * delegates shared field assembly to ProjectionBindingAssembler.
 */
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Relation\RelationInterface;
use ON\Data\ORM\Compiler\ProjectionBindingAssembler;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationRelationBinding;
use ON\Data\Query\Expression\AliasedExpression;
use ON\Data\Query\Expression\FieldRef;
use ON\Data\Query\Relation\RelationRef;
use ON\Data\Query\Relation\RelationSelection;
use ON\Data\Query\Selection\SelectionItem;
use ON\Data\Query\Selection\SelectionTag;
use ON\Data\Query\SelectQuery;

final class ProjectionCompiler
{
	private int $internalResultKeyCounter = 0;
	private ProjectionSelectionNormalizer $selectionNormalizer;
	private ProjectionBindingAssembler $bindingAssembler;

	public function __construct(
		?ProjectionSelectionNormalizer $selectionNormalizer = null,
		?ProjectionBindingAssembler $bindingAssembler = null,
	) {
		$this->selectionNormalizer = $selectionNormalizer ?? new ProjectionSelectionNormalizer();
		$this->bindingAssembler = $bindingAssembler ?? new ProjectionBindingAssembler();
	}

	public function compile(SelectQuery $query): RepresentationBinding
	{
		return $this->compileResult($query)->getBinding();
	}

	public function compileResult(SelectQuery $query): ProjectionCompilation
	{
		$this->internalResultKeyCounter = 0;

		$collection = $query->getCollection();
		$binding = new RepresentationBinding();
		$projectionIdentities = new ProjectionIdentityMap();

		$sourceResolver = new QueryProjectionSourceResolver($query);

		$this->compileRootScalarFields($binding, $query, $collection, $sourceResolver);
		$this->compileRelationSourcedFlatFields($binding, $query, $sourceResolver, $projectionIdentities);
		$this->compileRelationSelections($binding, $query);

		return new ProjectionCompilation($binding, $projectionIdentities);
	}

	private function compileRootScalarFields(
		RepresentationBinding $binding,
		SelectQuery $query,
		CollectionInterface $collection,
		QueryProjectionSourceResolver $sourceResolver,
	): void {
		$explicitSelections = $query->getSelections()->getExplicit();
		$selectedFields = $this->getRootExplicitScalarSelections($query);

		if ($explicitSelections === []) {
			$this->bindingAssembler->addDefaultCollectionFields($binding, $collection);
		} else {
			$this->addSelectedFields($binding, $sourceResolver, $selectedFields);
		}

		$this->bindingAssembler->addPrimaryKeyFields($binding, $collection);
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
		QueryProjectionSourceResolver $sourceResolver,
		array $selections,
	): void {
		$this->bindingAssembler->assembleInto(
			$binding,
			$this->selectionNormalizer->normalizeSelections($selections),
			$sourceResolver,
		);
	}

	private function compileRelationSourcedFlatFields(
		RepresentationBinding $binding,
		SelectQuery $query,
		QueryProjectionSourceResolver $sourceResolver,
		ProjectionIdentityMap $projectionIdentities,
	): void {
		/** @var array<string, RelationRef> $relationRefsByPath */
		$relationRefsByPath = [];

		foreach ($query->getSelections()->getExplicit() as $selection) {
			$resolved = $this->resolveRelationSourcedFieldRef($query, $selection);

			if ($resolved === null) {
				continue;
			}

			$relationRef = $resolved['relationRef'];
			$this->bindingAssembler->assembleInto(
				$binding,
				$this->selectionNormalizer->normalizeSelections([$selection]),
				$sourceResolver,
			);

			$relationRefsByPath[json_encode($relationRef->getPath(), JSON_THROW_ON_ERROR)] = $relationRef;
		}

		foreach ($relationRefsByPath as $relationRef) {
			$this->ensureRelatedIdentitySelections($binding, $query, $relationRef, $projectionIdentities);
		}
	}

	/**
	 * @return array{relationRef: RelationRef}|null
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
			'relationRef' => $source,
		];
	}

	private function ensureRelatedIdentitySelections(
		RepresentationBinding $binding,
		SelectQuery $query,
		RelationRef $relationRef,
		ProjectionIdentityMap $projectionIdentities,
	): void {
		$targetCollection = $relationRef->getCollection();

		foreach ($targetCollection->getPrimaryKey() as $fieldName) {
			if ($this->bindingAssembler->hasTemplateFieldFor($binding, $targetCollection, $fieldName)) {
				continue;
			}

			if ($projectionIdentities->get($targetCollection, $fieldName) !== null) {
				continue;
			}

			$resultKey = $this->generateInternalResultKey($query);
			$fieldRef = $relationRef->field($fieldName);
			$query->getSelections()->add(
				$fieldRef->as($resultKey),
				SelectionTag::INTERNAL,
			);
			$projectionIdentities->add($targetCollection, $fieldName, $resultKey);
		}
	}

	private function generateInternalResultKey(SelectQuery $query): string
	{
		do {
			$key = '_od_internal_' . ++$this->internalResultKeyCounter;
		} while ($query->getSelections()->hasSelectionKey($key));

		return $key;
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
				$ownerCollection,
				$relationName,
				$relatedBinding,
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
				$this->bindingAssembler->addTemplateField($binding, $targetCollection, $fieldName, $fieldName);
			}

			$this->bindingAssembler->addPrimaryKeyFields($binding, $targetCollection);
		} else {
			$this->bindingAssembler->addDefaultCollectionFields($binding, $targetCollection);
		}

		return $binding;
	}

	private function relationTargetCollection(RelationInterface $relation): CollectionInterface
	{
		return $relation->getCollection();
	}

}
