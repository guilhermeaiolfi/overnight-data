<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Schema\Query;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Relation\RelationInterface;
use ON\Data\ORM\Representation\Schema\RepresentationRelationSchema;
use ON\Data\ORM\Representation\Schema\RepresentationSchema;
use ON\Data\ORM\Representation\Schema\Shape\RepresentationFieldShape;
use ON\Data\ORM\Representation\Schema\Shape\RepresentationSchemaAssembler;
use ON\Data\ORM\Representation\Schema\Shape\RepresentationSource;
use ON\Data\ORM\Representation\Schema\Shape\RepresentationSourceResolverInterface;
use ON\Data\Query\Expression\AliasedExpression;
use ON\Data\Query\Expression\FieldRef;
use ON\Data\Query\Expression\StarExpression;
use ON\Data\Query\Relation\RelationRef;
use ON\Data\Query\Relation\RelationSelection;
use ON\Data\Query\Selection\SelectionItem;
use ON\Data\Query\SelectQuery;

/**
 * Compiles a SelectQuery selection graph into a structural RepresentationSchema
 * for flat mutable projection adoption.
 *
 * Exists as the query-side compiler: it normalizes selections, assembles scalar
 * field schemas, plans relation branches, and delegates shared field assembly
 * to RepresentationSchemaAssembler. Hidden identity selection planning is delegated
 * to QueryRepresentationIdentityPlanner.
 */
final class QueryRepresentationSchemaCompiler
{
	private QueryRepresentationSelectionNormalizer $selectionNormalizer;
	private RepresentationSchemaAssembler $schemaAssembler;
	private QueryRepresentationIdentityPlanner $identityPlanner;

	public function __construct(
		?QueryRepresentationSelectionNormalizer $selectionNormalizer = null,
		?RepresentationSchemaAssembler $schemaAssembler = null,
		?QueryRepresentationIdentityPlanner $identityPlanner = null,
	) {
		$this->selectionNormalizer = $selectionNormalizer ?? new QueryRepresentationSelectionNormalizer();
		$this->schemaAssembler = $schemaAssembler ?? new RepresentationSchemaAssembler();
		$this->identityPlanner = $identityPlanner ?? new QueryRepresentationIdentityPlanner();
	}

	public function compile(SelectQuery $query): RepresentationSchema
	{
		return $this->compileSchema($query);
	}

	public function compileSchema(SelectQuery $query): RepresentationSchema
	{
		$collection = $query->getCollection();
		$schema = new RepresentationSchema($collection);

		$sourceResolver = new QueryRepresentationSourceResolver($query);

		$this->compileRootScalarFields($schema, $query, $collection, $sourceResolver);
		$this->compileRelationSourcedFlatFields($schema, $query, $sourceResolver);
		$this->compileRelationSelections($schema, $query);

		return $schema;
	}

	public function compileResult(SelectQuery $query): QueryRepresentationPlan
	{
		$schema = $this->compileSchema($query);
		$sources = RepresentationSource::fromRepresentationSchema($schema);
		$identityColumns = $this->identityPlanner->plan($query, $sources);

		return new QueryRepresentationPlan($schema, $sources, $identityColumns);
	}

	private function compileRootScalarFields(
		RepresentationSchema $schema,
		SelectQuery $query,
		CollectionInterface $collection,
		QueryRepresentationSourceResolver $sourceResolver,
	): void {
		$shapes = $this->hasExplicitRootStarSelection($query)
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
		RepresentationSourceResolverInterface $sourceResolver,
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
			if ($selection->getExpression() instanceof StarExpression) {
				continue;
			}

			if ($this->resolveRootFieldRef($query, $selection) !== null) {
				$selections[] = $selection;
			}
		}

		return $selections;
	}

	private function hasExplicitRootStarSelection(SelectQuery $query): bool
	{
		foreach ($query->getSelections()->getExplicit() as $selection) {
			$expression = $selection->getExpression();

			if (! $expression instanceof StarExpression) {
				continue;
			}

			if ($expression->getSource() === $query) {
				return true;
			}
		}

		return false;
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
		QueryRepresentationSourceResolver $sourceResolver,
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
		$sourceResolver = new RootRepresentationSourceResolver($targetCollection);
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
	 * @return list<RepresentationFieldShape>
	 */
	private function explicitFieldShapes(array $fieldNames, object $source): array
	{
		$shapes = [];

		foreach ($fieldNames as $fieldName) {
			$shapes[] = new RepresentationFieldShape($fieldName, $source, $fieldName);
		}

		return $shapes;
	}
}
