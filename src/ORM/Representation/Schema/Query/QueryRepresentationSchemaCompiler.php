<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Schema\Query;

use InvalidArgumentException;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Relation\RelationInterface;
use ON\Data\ORM\Exception\StateException;
use ON\Data\ORM\Representation\Schema\RepresentationFieldSchema;
use ON\Data\ORM\Representation\Schema\RepresentationRelationSchema;
use ON\Data\ORM\Representation\Schema\RepresentationSchema;
use ON\Data\Query\Expression\AliasedExpression;
use ON\Data\Query\Expression\FieldRef;
use ON\Data\Query\Expression\StarExpression;
use ON\Data\Query\QuerySourceInterface;
use ON\Data\Query\Relation\RelationRef;
use ON\Data\Query\Relation\RelationSelection;
use ON\Data\Query\Selection\SelectionItem;
use ON\Data\Query\SelectQuery;

/**
 * Compiles a SelectQuery selection graph into a structural RepresentationSchema
 * for flat mutable projection adoption.
 *
 * Does not plan identities or mutate the query — that belongs to
 * {@see QueryRepresentationIdentityPlanner} at writable prepare time.
 */
final class QueryRepresentationSchemaCompiler
{
	public function compile(SelectQuery $query): RepresentationSchema
	{
		$collection = $query->getCollection();
		$schema = new RepresentationSchema($collection);

		$this->compileRootScalarFields($schema, $query, $collection);
		$this->compileRelationSourcedFlatFields($schema, $query);
		$this->compileRelationSelections($schema, $query);

		return $schema;
	}

	private function compileRootScalarFields(
		RepresentationSchema $schema,
		SelectQuery $query,
		CollectionInterface $collection,
	): void {
		$fields = $this->hasExplicitRootStarSelection($query)
			? $this->defaultFields($collection)
			: $this->fieldsFromSelections($query, $this->getRootExplicitScalarSelections($query));

		$this->addFields($schema, $fields);
		$this->assemblePrimaryKeyFields($schema, $collection);
	}

	/**
	 * Adds primary-key fields that are not already bound for the given source path.
	 *
	 * @param list<string> $sourcePath
	 */
	private function assemblePrimaryKeyFields(
		RepresentationSchema $schema,
		CollectionInterface $collection,
		array $sourcePath = [],
	): void {
		$fields = [];

		foreach ($this->primaryKeyFields($collection, $sourcePath) as $field) {
			if ($schema->hasFieldForSource($sourcePath, $field->getFieldName())) {
				continue;
			}

			$fields[] = $field;
		}

		$this->addFields($schema, $fields);
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
	): void {
		foreach ($query->getSelections()->getExplicit() as $selection) {
			if (! $this->isRelationSourcedFieldSelection($query, $selection)) {
				continue;
			}

			$this->addFields($schema, $this->fieldsFromSelections($query, [$selection]));
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
		$explicitFields = $selection->getFields();

		$fields = $explicitFields !== null
			? $this->explicitFields($explicitFields, $targetCollection)
			: $this->defaultFields($targetCollection);

		$this->addFields($schema, $fields);
		$this->assemblePrimaryKeyFields($schema, $targetCollection);

		return $schema;
	}

	/**
	 * @param list<RepresentationFieldSchema> $fields
	 */
	private function addFields(RepresentationSchema $schema, array $fields): void
	{
		foreach ($fields as $field) {
			if ($schema->hasField($field->getPath())) {
				continue;
			}

			$schema->addField($field);
		}
	}

	/**
	 * @param list<string> $sourcePath
	 *
	 * @return list<RepresentationFieldSchema>
	 */
	private function defaultFields(CollectionInterface $collection, array $sourcePath = []): array
	{
		$fields = [];

		foreach ($collection->getFields() as $field) {
			$fields[] = new RepresentationFieldSchema(
				$field->getName(),
				$collection,
				$field->getName(),
				writable: true,
				skipWhenMissing: true,
				sourcePath: $sourcePath,
			);
		}

		return $fields;
	}

	/**
	 * @param list<string> $sourcePath
	 *
	 * @return list<RepresentationFieldSchema>
	 */
	private function primaryKeyFields(CollectionInterface $collection, array $sourcePath = []): array
	{
		$fields = [];

		foreach ($collection->getPrimaryKey() as $fieldName) {
			$fields[] = new RepresentationFieldSchema(
				$fieldName,
				$collection,
				$fieldName,
				writable: false,
				skipWhenMissing: true,
				sourcePath: $sourcePath,
			);
		}

		return $fields;
	}

	/**
	 * @param list<string> $fieldNames
	 *
	 * @return list<RepresentationFieldSchema>
	 */
	private function explicitFields(array $fieldNames, CollectionInterface $collection): array
	{
		$fields = [];

		foreach ($fieldNames as $fieldName) {
			$fields[] = new RepresentationFieldSchema(
				$fieldName,
				$collection,
				$fieldName,
				writable: true,
				skipWhenMissing: true,
			);
		}

		return $fields;
	}

	/**
	 * @param list<SelectionItem> $selections
	 *
	 * @return list<RepresentationFieldSchema>
	 */
	private function fieldsFromSelections(SelectQuery $query, array $selections): array
	{
		$fields = [];

		foreach ($selections as $selection) {
			$field = $this->fieldFromSelection($query, $selection);
			if ($field instanceof RepresentationFieldSchema) {
				$fields[] = $field;
			}
		}

		return $fields;
	}

	private function fieldFromSelection(SelectQuery $query, SelectionItem $selection): ?RepresentationFieldSchema
	{
		$expression = $selection->getExpression();
		$publicPath = $selection->getSelectionKey();

		if ($expression instanceof AliasedExpression) {
			$publicPath = $expression->getAlias();
			$expression = $expression->getExpression();
		}

		if (! $expression instanceof FieldRef) {
			return null;
		}

		[$collection, $sourcePath] = $this->resolveFieldSource($query, $expression->getSource());

		return new RepresentationFieldSchema(
			$publicPath,
			$collection,
			$expression->getName(),
			writable: true,
			skipWhenMissing: true,
			sourcePath: $sourcePath,
		);
	}

	/**
	 * @return array{0: CollectionInterface, 1: list<string>}
	 */
	private function resolveFieldSource(SelectQuery $query, object $source): array
	{
		if (! $source instanceof QuerySourceInterface) {
			throw new InvalidArgumentException('Projection field sources must be query sources.');
		}

		if ($source === $query) {
			return [$query->getCollection(), []];
		}

		if ($source instanceof RelationRef && $source->getQuery() === $query) {
			return [$source->getCollection(), $source->getPath()];
		}

		throw new StateException('Cannot resolve projection source because it does not belong to this query.');
	}
}
