<?php

declare(strict_types=1);

namespace ON\Data\Query\Schema;

use InvalidArgumentException;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Query\Expression\AliasedExpression;
use ON\Data\Query\Expression\FieldRef;
use ON\Data\Query\Expression\StarExpression;
use ON\Data\Query\Expression\ValueExpressionInterface;
use ON\Data\Query\QuerySourceInterface;
use ON\Data\Query\Relation\LoadBranch;
use ON\Data\Query\Relation\RelationRef;
use ON\Data\Query\Relation\RelationSelection;
use ON\Data\Query\Selection\SelectionItem;
use ON\Data\Query\SelectQuery;
use ON\Data\Schema\Exception\InvalidSchemaException;
use ON\Data\Schema\RelationLoadKnowledge;
use ON\Data\Schema\RepresentationExpressionSchema;
use ON\Data\Schema\RepresentationFieldSchema;
use ON\Data\Schema\RepresentationRelationSchema;
use ON\Data\Schema\RepresentationSchema;
use ON\Data\Schema\SchemaFields;

/**
 * Compiles a SelectQuery selection graph into a structural RepresentationSchema
 * (result shape: public paths plus implicit source identities).
 *
 * Does not mutate the query. Join/correlation keys stay on loaders
 * ({@see LoadBranch::requireFields()}). Hidden source primary keys are Implicit
 * fields; non-root identities use a source-qualified path (`company.id`).
 */
final class QueryRepresentationSchemaCompiler
{
	public function compile(SelectQuery $query): RepresentationSchema
	{
		$collection = $query->getCollection();
		$schema = new RepresentationSchema($collection);

		$explicit = $query->getSelections()->getExplicit();
		$rootFields = [];
		$relatedFields = [];
		foreach ($explicit as $selection) {
			$expression = $selection->getExpression();
			if ($expression instanceof AliasedExpression) {
				$expression = $expression->getExpression();
			}
			if (! $expression instanceof FieldRef && ! $expression instanceof StarExpression) {
				continue;
			}

			$source = $expression->getSource();
			if ($source === $query) {
				$rootFields[] = $selection;
			} elseif ($expression instanceof FieldRef && $source instanceof RelationRef && $source->getQuery() === $query) {
				$relatedFields[] = $selection;
			}
		}

		$this->addFields($schema, $this->fieldsFromSelections($query, $rootFields));
		$this->addExpressions($schema, $this->expressionsFromSelections($query, $explicit));
		SchemaFields::assemblePrimaryKeyFields($schema, $collection);
		$this->addFields($schema, $this->fieldsFromSelections($query, $relatedFields));
		SchemaFields::assembleFlatSourcePrimaryKeys($schema);
		$this->compileRelationSelections($schema, $query);

		return $schema;
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
			$pathKey = RelationSelection::pathKey($path);
			$parentPathKey = $selection->getParentPathKey();
			$parentSchema = $parentPathKey === null
				? $rootSchema
				: $schemasByPath[$parentPathKey];
			$ownerCollection = $selection->getRelationRef()->getParentRelation()?->getCollection() ?? $query->getCollection();
			$relationName = $selection->getName();
			$relatedSchema = $this->compileRelationSchema($selection);

			$parentSchema->addRelation(new RepresentationRelationSchema(
				$relationName,
				$ownerCollection,
				$relationName,
				$relatedSchema,
				loadKnowledge: $this->loadKnowledgeForSelection($selection),
				visible: $selection->isVisible(),
				hasScalarSelection: $selection->isLoaded(),
			));

			$schemasByPath[$pathKey] = $relatedSchema;
		}
	}

	/**
	 * Query relation selections are Full when they load the association as defined.
	 * Any relation-level where / limit / offset means a subset → Partial.
	 * Order and field lists do not affect membership completeness.
	 */
	private function loadKnowledgeForSelection(RelationSelection $selection): RelationLoadKnowledge
	{
		if (
			$selection->getConditions() !== []
			|| $selection->getLimit() !== null
			|| $selection->hasOffset()
		) {
			return RelationLoadKnowledge::Partial;
		}

		return RelationLoadKnowledge::Full;
	}

	private function compileRelationSchema(RelationSelection $selection): RepresentationSchema
	{
		$relationRef = $selection->getRelationRef();
		$targetCollection = $relationRef->getDefinition()->getCollection();
		$schema = new RepresentationSchema($targetCollection);

		if ($selection->hasDefaultSelection()) {
			$this->addFields($schema, SchemaFields::defaultFields($targetCollection));
		} else {
			$explicit = $selection->getSelections()->getExplicit();
			$this->addFields($schema, $this->fieldsFromSelections($relationRef, $explicit));
			$this->addExpressions($schema, $this->expressionsFromSelections($relationRef, $explicit));
			SchemaFields::assembleFlatSourcePrimaryKeys($schema);
		}

		SchemaFields::assemblePrimaryKeyFields($schema, $targetCollection);

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
	 * @param list<RepresentationExpressionSchema> $expressions
	 */
	private function addExpressions(RepresentationSchema $schema, array $expressions): void
	{
		foreach ($expressions as $expression) {
			if ($schema->hasExpression($expression->getPath()) || $schema->hasPath($expression->getPath())) {
				continue;
			}

			$schema->addExpression($expression);
		}
	}

	/**
	 * @param list<SelectionItem> $selections
	 *
	 * @return list<RepresentationFieldSchema>
	 */
	private function fieldsFromSelections(SelectQuery|RelationRef $level, array $selections): array
	{
		$fields = [];

		foreach ($selections as $selection) {
			$expression = $selection->getExpression();

			if ($expression instanceof StarExpression && $expression->getSource() === $level) {
				return SchemaFields::defaultFields($level->getCollection());
			}

			$field = $this->fieldFromSelection($level, $selection);
			if ($field instanceof RepresentationFieldSchema) {
				$fields[] = $field;
			}
		}

		return $fields;
	}

	/**
	 * @param list<SelectionItem> $selections
	 *
	 * @return list<RepresentationExpressionSchema>
	 */
	private function expressionsFromSelections(SelectQuery|RelationRef $level, array $selections): array
	{
		$expressions = [];

		foreach ($selections as $selection) {
			$expression = $this->expressionFromSelection($level, $selection);
			if ($expression instanceof RepresentationExpressionSchema) {
				$expressions[] = $expression;
			}
		}

		return $expressions;
	}

	private function expressionFromSelection(
		SelectQuery|RelationRef $level,
		SelectionItem $selection,
	): ?RepresentationExpressionSchema {
		$expression = $selection->getExpression();

		if ($expression instanceof StarExpression) {
			return null;
		}

		$publicPath = $selection->getSelectionKey();

		if ($expression instanceof AliasedExpression) {
			$publicPath = $expression->getAlias();
			$expression = $expression->getExpression();
		}

		if ($expression instanceof FieldRef) {
			return null;
		}

		if (! $expression instanceof ValueExpressionInterface) {
			return null;
		}

		if ($publicPath === '') {
			return null;
		}

		return new RepresentationExpressionSchema($publicPath, $expression, $level);
	}

	private function fieldFromSelection(SelectQuery|RelationRef $level, SelectionItem $selection): ?RepresentationFieldSchema
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

		[$collection, $sourcePath] = $this->resolveFieldSource($level, $expression->getSource());

		return new RepresentationFieldSchema(
			$publicPath,
			$collection,
			$expression->getName(),
			writable: true,
			sourcePath: $sourcePath,
		);
	}

	/**
	 * @return array{0: CollectionInterface, 1: list<string>}
	 */
	private function resolveFieldSource(SelectQuery|RelationRef $level, object $source): array
	{
		if (! $source instanceof QuerySourceInterface) {
			throw new InvalidArgumentException('Projection field sources must be query sources.');
		}

		if ($source === $level) {
			return [$level->getCollection(), []];
		}

		if ($source instanceof RelationRef && $source->isUnder($level)) {
			return [$source->getCollection(), $source->relativeTo($level)];
		}

		throw new InvalidSchemaException('Cannot resolve projection source because it does not belong to this query level.');
	}
}
