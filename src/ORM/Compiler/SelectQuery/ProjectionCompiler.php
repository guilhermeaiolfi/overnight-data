<?php

declare(strict_types=1);

namespace ON\Data\ORM\Compiler\SelectQuery;

/**
 * Compiles a SelectQuery selection graph into a structural RepresentationSchema
 * for flat mutable projection adoption.
 *
 * Exists as the query-side compiler: it normalizes selections, assembles scalar
 * field schemas, plans relation branches, and delegates shared field assembly
 * to ProjectionSchemaAssembler. Hidden identity selection planning is delegated
 * to ProjectionIdentityPlanner.
 */
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Relation\RelationInterface;
use ON\Data\ORM\Compiler\ProjectionFieldShape;
use ON\Data\ORM\Compiler\ProjectionSchemaAssembler;
use ON\Data\ORM\Compiler\ProjectionSource;
use ON\Data\ORM\Compiler\ProjectionSourceResolverInterface;
use ON\Data\ORM\State\RepresentationRelationSchema;
use ON\Data\ORM\State\RepresentationSchema;
use ON\Data\Query\Expression\AliasedExpression;
use ON\Data\Query\Expression\FieldRef;
use ON\Data\Query\Relation\RelationRef;
use ON\Data\Query\Relation\RelationSelection;
use ON\Data\Query\Selection\SelectionItem;
use ON\Data\Query\SelectQuery;

final class ProjectionCompiler
{
	private ProjectionSelectionNormalizer $selectionNormalizer;
	private ProjectionSchemaAssembler $schemaAssembler;
	private ProjectionIdentityPlanner $identityPlanner;

	public function __construct(
		?ProjectionSelectionNormalizer $selectionNormalizer = null,
		?ProjectionSchemaAssembler $schemaAssembler = null,
		?ProjectionIdentityPlanner $identityPlanner = null,
	) {
		$this->selectionNormalizer = $selectionNormalizer ?? new ProjectionSelectionNormalizer();
		$this->schemaAssembler = $schemaAssembler ?? new ProjectionSchemaAssembler();
		$this->identityPlanner = $identityPlanner ?? new ProjectionIdentityPlanner();
	}

	public function compile(SelectQuery $query): RepresentationSchema
	{
		return $this->compileSchema($query);
	}

	public function compileSchema(SelectQuery $query): RepresentationSchema
	{
		$collection = $query->getCollection();
		$schema = new RepresentationSchema($collection);

		$sourceResolver = new QueryProjectionSourceResolver($query);

		$this->compileRootScalarFields($schema, $query, $collection, $sourceResolver);
		$this->compileRelationSourcedFlatFields($schema, $query, $sourceResolver);
		$this->compileRelationSelections($schema, $query);

		return $schema;
	}

	public function compileResult(SelectQuery $query): ProjectionCompilation
	{
		$schema = $this->compileSchema($query);
		$sources = ProjectionSource::fromRepresentationSchema($schema);
		$identityColumns = $this->identityPlanner->plan($query, $sources);

		return new ProjectionCompilation($schema, $sources, $identityColumns);
	}

	private function compileRootScalarFields(
		RepresentationSchema $schema,
		SelectQuery $query,
		CollectionInterface $collection,
		QueryProjectionSourceResolver $sourceResolver,
	): void {
		$shapes = $query->getSelections()->getExplicit() === []
			? $this->schemaAssembler->defaultFieldShapes($collection, $query)
			: $this->selectionNormalizer->normalizeSelections($this->getRootExplicitScalarSelections($query));

		$this->schemaAssembler->assembleInto($schema, $shapes, $sourceResolver);

		$this->assemblePrimaryKeyFields($schema, $collection, $query, $sourceResolver);
	}

	/**
	 * Adds primary-key field shapes that are not already bound for the root source
	 * ([]). Dedup is by source path + field name so an explicit primary key
	 * selection (including an aliased one) is not shadowed by a read-only copy.
	 */
	private function assemblePrimaryKeyFields(
		RepresentationSchema $schema,
		CollectionInterface $collection,
		object $source,
		ProjectionSourceResolverInterface $sourceResolver,
	): void {
		$shapes = [];

		foreach ($this->schemaAssembler->primaryKeyFieldShapes($collection, $source) as $shape) {
			if ($schema->hasFieldForSource([], $shape->getFieldName())) {
				continue;
			}

			$shapes[] = $shape;
		}

		$this->schemaAssembler->assembleInto($schema, $shapes, $sourceResolver);
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
		RepresentationSchema $schema,
		SelectQuery $query,
		QueryProjectionSourceResolver $sourceResolver,
	): void {
		foreach ($query->getSelections()->getExplicit() as $selection) {
			if (! $this->isRelationSourcedFieldSelection($query, $selection)) {
				continue;
			}

			$this->schemaAssembler->assembleInto(
				$schema,
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

	private function compileRelationSelections(RepresentationSchema $rootSchema, SelectQuery $query): void
	{
		$relationSelections = $query->getRelationSelections()->getAll();

		usort(
			$relationSelections,
			static fn (RelationSelection $left, RelationSelection $right): int => count($left->getPath()) <=> count($right->getPath()),
		);

		/** @var array<string, RepresentationSchema> $schemasByPath */
		$schemasByPath = [];

		foreach ($relationSelections as $selection) {
			$path = $selection->getPath();
			$pathKey = json_encode($path, JSON_THROW_ON_ERROR);
			$parentPathKey = $selection->getParentPathKey();
			$parentSchema = $parentPathKey === null
				? $rootSchema
				: $schemasByPath[$parentPathKey];
			$ownerCollection = $this->resolveOwnerCollection($query, $path);
			$relationName = $selection->getName();
			$relatedSchema = $this->compileRelationSchema($selection);

			$parentSchema->addRelation(new RepresentationRelationSchema(
				$relationName,
				$ownerCollection,
				$relationName,
				$relatedSchema,
			));

			$schemasByPath[$pathKey] = $relatedSchema;
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

	private function compileRelationSchema(RelationSelection $selection): RepresentationSchema
	{
		$targetCollection = $selection->getRelationRef()->getDefinition()->getCollection();
		$schema = new RepresentationSchema($targetCollection);
		$sourceResolver = new RootSourceResolver($targetCollection);
		$explicitFields = $selection->getFields();

		$shapes = $explicitFields !== null
			? $this->explicitFieldShapes($explicitFields, $targetCollection)
			: $this->schemaAssembler->defaultFieldShapes($targetCollection, $targetCollection);

		$this->schemaAssembler->assembleInto($schema, $shapes, $sourceResolver);
		$this->assemblePrimaryKeyFields($schema, $targetCollection, $targetCollection, $sourceResolver);

		return $schema;
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
