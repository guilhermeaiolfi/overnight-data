<?php

declare(strict_types=1);

namespace ON\Data\ORM\Compiler\SelectQuery;

/**
 * Compiles a SelectQuery selection graph into a structural RepresentationBinding
 * for flat mutable projection adoption.
 *
 * Exists as the query-side compiler: it normalizes selections, assembles scalar
 * field bindings, plans relation branches, and delegates shared field assembly
 * to ProjectionBindingAssembler. Hidden identity selection planning is delegated
 * to ProjectionIdentityPlanner.
 */
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Relation\RelationInterface;
use ON\Data\ORM\Compiler\ProjectionBindingAssembler;
use ON\Data\ORM\Compiler\ProjectionFieldShape;
use ON\Data\ORM\Compiler\ProjectionSourceResolverInterface;
use ON\Data\ORM\State\RepresentationBinding;
use ON\Data\ORM\State\RepresentationRelationBinding;
use ON\Data\Query\Expression\AliasedExpression;
use ON\Data\Query\Expression\FieldRef;
use ON\Data\Query\Relation\RelationRef;
use ON\Data\Query\Relation\RelationSelection;
use ON\Data\Query\Selection\SelectionItem;
use ON\Data\Query\SelectQuery;

final class ProjectionCompiler
{
	private ProjectionSelectionNormalizer $selectionNormalizer;
	private ProjectionBindingAssembler $bindingAssembler;
	private ProjectionIdentityPlanner $identityPlanner;

	public function __construct(
		?ProjectionSelectionNormalizer $selectionNormalizer = null,
		?ProjectionBindingAssembler $bindingAssembler = null,
		?ProjectionIdentityPlanner $identityPlanner = null,
	) {
		$this->selectionNormalizer = $selectionNormalizer ?? new ProjectionSelectionNormalizer();
		$this->bindingAssembler = $bindingAssembler ?? new ProjectionBindingAssembler();
		$this->identityPlanner = $identityPlanner ?? new ProjectionIdentityPlanner();
	}

	public function compile(SelectQuery $query): RepresentationBinding
	{
		return $this->compileBinding($query);
	}

	public function compileBinding(SelectQuery $query): RepresentationBinding
	{
		$collection = $query->getCollection();
		$binding = new RepresentationBinding($collection);

		$sourceResolver = new QueryProjectionSourceResolver($query);

		$this->compileRootScalarFields($binding, $query, $collection, $sourceResolver);
		$this->compileRelationSourcedFlatFields($binding, $query, $sourceResolver);
		$this->compileRelationSelections($binding, $query);

		return $binding;
	}

	public function compileResult(SelectQuery $query): ProjectionCompilation
	{
		$binding = $this->compileBinding($query);
		$identityColumns = $this->identityPlanner->plan($query, $binding);

		return new ProjectionCompilation($binding, $identityColumns);
	}

	private function compileRootScalarFields(
		RepresentationBinding $binding,
		SelectQuery $query,
		CollectionInterface $collection,
		QueryProjectionSourceResolver $sourceResolver,
	): void {
		$shapes = $query->getSelections()->getExplicit() === []
			? $this->bindingAssembler->defaultFieldShapes($collection, $query)
			: $this->selectionNormalizer->normalizeSelections($this->getRootExplicitScalarSelections($query));

		$this->bindingAssembler->assembleInto($binding, $shapes, $sourceResolver);

		$this->assemblePrimaryKeyFields($binding, $collection, $query, $sourceResolver);
	}

	/**
	 * Adds primary-key field shapes that are not already bound for the root source
	 * ([]). Dedup is by source path + field name so an explicit primary key
	 * selection (including an aliased one) is not shadowed by a read-only copy.
	 */
	private function assemblePrimaryKeyFields(
		RepresentationBinding $binding,
		CollectionInterface $collection,
		object $source,
		ProjectionSourceResolverInterface $sourceResolver,
	): void {
		$shapes = [];

		foreach ($this->bindingAssembler->primaryKeyFieldShapes($collection, $source) as $shape) {
			if ($binding->hasFieldForSource([], $shape->getFieldName())) {
				continue;
			}

			$shapes[] = $shape;
		}

		$this->bindingAssembler->assembleInto($binding, $shapes, $sourceResolver);
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

	private function compileRelationSourcedFlatFields(
		RepresentationBinding $binding,
		SelectQuery $query,
		QueryProjectionSourceResolver $sourceResolver,
	): void {
		foreach ($query->getSelections()->getExplicit() as $selection) {
			if (! $this->isRelationSourcedFieldSelection($query, $selection)) {
				continue;
			}

			$this->bindingAssembler->assembleInto(
				$binding,
				$this->selectionNormalizer->normalizeSelections([$selection]),
				$sourceResolver,
			);
		}
	}

	private function isRelationSourcedFieldSelection(SelectQuery $query, SelectionItem $selection): bool
	{
		$expression = $selection->getExpression();

		if ($expression instanceof AliasedExpression) {
			$expression = $expression->getExpression();
		}

		if (! $expression instanceof FieldRef) {
			return false;
		}

		if ($this->resolveRootFieldRef($query, $selection) !== null) {
			return false;
		}

		$source = $expression->getSource();

		return $source instanceof RelationRef && $source->getQuery() === $query;
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
		$targetCollection = $selection->getRelationRef()->getDefinition()->getCollection();
		$binding = new RepresentationBinding($targetCollection);
		$sourceResolver = new RootSourceResolver($targetCollection);
		$explicitFields = $selection->getFields();

		$shapes = $explicitFields !== null
			? $this->explicitFieldShapes($explicitFields, $targetCollection)
			: $this->bindingAssembler->defaultFieldShapes($targetCollection, $targetCollection);

		$this->bindingAssembler->assembleInto($binding, $shapes, $sourceResolver);
		$this->assemblePrimaryKeyFields($binding, $targetCollection, $targetCollection, $sourceResolver);

		return $binding;
	}

	/**
	 * @param list<string> $fieldNames
	 * @return list<ProjectionFieldShape>
	 */
	private function explicitFieldShapes(array $fieldNames, object $source): array
	{
		$shapes = [];

		foreach ($fieldNames as $fieldName) {
			$shapes[] = new ProjectionFieldShape($fieldName, $source, $fieldName);
		}

		return $shapes;
	}
}
