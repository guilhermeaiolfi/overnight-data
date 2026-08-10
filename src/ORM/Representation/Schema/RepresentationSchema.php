<?php

declare(strict_types=1);

namespace ON\Data\ORM\Representation\Schema;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Key;
use ON\Data\ORM\Exception\StateException;
use stdClass;

/**
 * Place + persistence provenance graph for one representation shape: a root
 * collection plus field, relation, and expression path maps.
 *
 * One schema serves two attachment modes:
 * - **Graph** — nested `relations` with related schemas (entity-shaped objects).
 * - **Flat** — fields with non-empty `sourcePath` spanning related collections
 *   (no relation containers); see {@see getSources()}.
 *
 * Query assemble uses {@see getPublicScalarPaths()} when a fetch schema is
 * present (Public fields + expressions; Identity enrichment excluded). Plain
 * assemble without a compiled schema still falls back to explicit selections.
 *
 * Durable model compiled from queries or built manually; consumed by Session
 * sync/adoption and by Query assemble. Query may import this type as the
 * intentional ORM boundary exception (fetch still uses LoadBranch; selections
 * stay on SelectionList).
 */
final class RepresentationSchema
{
	/** @var array<string, RepresentationFieldSchema> */
	private array $fields = [];
	/** @var array<string, RepresentationRelationSchema> */
	private array $relations = [];
	/** @var array<string, RepresentationExpressionSchema> */
	private array $expressions = [];
	/** @var list<string> */
	private array $paths = [];
	/** @var list<RepresentationSource>|null */
	private ?array $sourcesCache = null;

	public function __construct(
		private CollectionInterface $collection,
	) {
	}

	public static function forPrimaryKey(CollectionInterface $collection): self
	{
		$schema = new self($collection);
		foreach ($collection->getPrimaryKey() as $fieldName) {
			$schema->addField(new RepresentationFieldSchema($fieldName, $collection, $fieldName));
		}

		return $schema;
	}

	public static function representationForKey(Key $key): object
	{
		$representation = new stdClass();
		foreach ($key->getValues() as $fieldName => $value) {
			$representation->{$fieldName} = $value;
		}

		return $representation;
	}

	public function getCollection(): CollectionInterface
	{
		return $this->collection;
	}

	public function getCollectionName(): string
	{
		return $this->collection->getName();
	}

	/**
	 * True when the schema has at least one field or relation, and every field
	 * collection and relation owner collection agrees with the root collection.
	 *
	 * Flat mixed projections (multiple collections) and empty schemas are not homogeneous.
	 */
	public function isHomogeneous(): bool
	{
		if ($this->fields === [] && $this->relations === []) {
			return false;
		}

		$rootName = $this->collection->getName();
		foreach ($this->fields as $fieldSchema) {
			if ($fieldSchema->getCollectionName() !== $rootName) {
				return false;
			}
		}

		foreach ($this->relations as $relationSchema) {
			if ($relationSchema->getOwnerCollectionName() !== $rootName) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Root collection when {@see isHomogeneous()}; otherwise throws with adoption/sync context.
	 */
	public function requireHomogeneousCollection(bool $isRoot = true): CollectionInterface
	{
		if ($this->isHomogeneous()) {
			return $this->collection;
		}

		if ($this->fields === [] && $this->relations === []) {
			if ($isRoot) {
				throw new StateException('Cannot synchronize untracked root representation because untracked root sync needs a schema targeting one collection.');
			}

			throw new StateException('Cannot adopt representation graph because a related schema does not target a collection.');
		}

		$rootName = $this->collection->getName();
		foreach ($this->fields as $fieldSchema) {
			if ($fieldSchema->getCollectionName() !== $rootName) {
				throw $this->heterogeneousCollectionException(
					$fieldSchema->getPath(),
					$fieldSchema->getCollectionName(),
					$rootName,
					$isRoot,
				);
			}
		}

		foreach ($this->relations as $relationSchema) {
			if ($relationSchema->getOwnerCollectionName() !== $rootName) {
				throw $this->heterogeneousCollectionException(
					$relationSchema->getPath(),
					$relationSchema->getOwnerCollectionName(),
					$rootName,
					$isRoot,
				);
			}
		}

		throw new StateException('Cannot adopt representation because the schema is not homogeneous.');
	}

	private function heterogeneousCollectionException(
		string $path,
		string $nextName,
		string $currentName,
		bool $isRoot,
	): StateException {
		if ($isRoot) {
			return new StateException(sprintf(
				"Cannot synchronize untracked root representation because untracked root sync needs a schema targeting one collection; path '%s' targets collection '%s' after '%s'.",
				$path,
				$nextName,
				$currentName,
			));
		}

		return new StateException(sprintf(
			"Cannot adopt representation graph because related schema path '%s' targets collection '%s' after '%s'.",
			$path,
			$nextName,
			$currentName,
		));
	}

	public function addField(RepresentationFieldSchema $fieldSchema): void
	{
		$path = $fieldSchema->getPath();
		$this->assertPathIsAvailable($path);

		$this->fields[$path] = $fieldSchema;
		$this->paths[] = $path;
		$this->invalidateSources();
	}

	public function hasField(string $path): bool
	{
		return array_key_exists($path, $this->fields);
	}

	public function getField(string $path): RepresentationFieldSchema
	{
		if (! array_key_exists($path, $this->fields)) {
			throw new StateException(sprintf("Representation schema does not contain field path '%s'.", $path));
		}

		return $this->fields[$path];
	}

	/**
	 * @return list<RepresentationFieldSchema>
	 */
	public function getFields(): array
	{
		return array_values($this->fields);
	}

	/**
	 * Public place keys for flat related fields at this level (`sourcePath !== []`).
	 * Identity enrichment and own-level fields are omitted.
	 *
	 * @return list<string>
	 */
	public function getFlatFieldPaths(): array
	{
		$keys = [];

		foreach ($this->fields as $field) {
			if ($field->getSourcePath() === [] || ! $field->isPublicPlace()) {
				continue;
			}

			$keys[] = $field->getPath();
		}

		return $keys;
	}

	/**
	 * Flat place keys at a nested relation path from this root (empty = this level).
	 *
	 * @param list<string> $relationPath
	 * @return list<string>
	 */
	public function flatPlaceKeysAt(array $relationPath): array
	{
		$node = $this->findRelatedSchemaAt($relationPath);

		return $node === null ? [] : $node->getFlatFieldPaths();
	}

	/**
	 * Ordered public scalar place paths at this level (Public fields + expressions).
	 * Excludes Identity enrichment and relation containers.
	 *
	 * @return list<string>
	 */
	public function getPublicScalarPaths(): array
	{
		$keys = [];

		foreach ($this->paths as $path) {
			if ($this->hasExpression($path)) {
				$keys[] = $path;

				continue;
			}

			if ($this->hasField($path) && $this->getField($path)->isPublicPlace()) {
				$keys[] = $path;
			}
		}

		return $keys;
	}

	/**
	 * @return list<RepresentationFieldSchema>
	 */
	public function getWritableFieldSchemas(): array
	{
		return array_values(array_filter(
			$this->fields,
			static fn (RepresentationFieldSchema $fieldSchema): bool => $fieldSchema->isWritable()
		));
	}

	/**
	 * @return list<RepresentationFieldSchema>
	 */
	public function getReadOnlyFieldSchemas(): array
	{
		return array_values(array_filter(
			$this->fields,
			static fn (RepresentationFieldSchema $fieldSchema): bool => $fieldSchema->isReadOnly()
		));
	}

	/**
	 * @param list<string> $sourcePath
	 */
	public function getFieldForSource(array $sourcePath, string $fieldName): ?RepresentationFieldSchema
	{
		$sourceKey = RepresentationFieldSchema::sourcePathKey($sourcePath);

		foreach ($this->fields as $field) {
			if ($field->getSourcePathKey() === $sourceKey && $field->getFieldName() === $fieldName) {
				return $field;
			}
		}

		return null;
	}

	/**
	 * @param list<string> $sourcePath
	 */
	public function hasFieldForSource(array $sourcePath, string $fieldName): bool
	{
		return $this->getFieldForSource($sourcePath, $fieldName) instanceof RepresentationFieldSchema;
	}

	public function addRelation(RepresentationRelationSchema $relationSchema): void
	{
		$path = $relationSchema->getPath();
		$this->assertPathIsAvailable($path);

		$this->relations[$path] = $relationSchema;
		$this->paths[] = $path;
		$this->invalidateSources();
	}

	public function hasRelation(string $path): bool
	{
		return array_key_exists($path, $this->relations);
	}

	public function getRelation(string $path): RepresentationRelationSchema
	{
		if (! array_key_exists($path, $this->relations)) {
			throw new StateException(sprintf("Representation schema does not contain relation path '%s'.", $path));
		}

		return $this->relations[$path];
	}

	/**
	 * @return list<RepresentationRelationSchema>
	 */
	public function getRelations(): array
	{
		return array_values($this->relations);
	}

	public function addExpression(RepresentationExpressionSchema $expressionSchema): void
	{
		$path = $expressionSchema->getPath();
		$this->assertPathIsAvailable($path);

		$this->expressions[$path] = $expressionSchema;
		$this->paths[] = $path;
	}

	public function hasExpression(string $path): bool
	{
		return array_key_exists($path, $this->expressions);
	}

	public function getExpression(string $path): RepresentationExpressionSchema
	{
		if (! array_key_exists($path, $this->expressions)) {
			throw new StateException(sprintf("Representation schema does not contain expression path '%s'.", $path));
		}

		return $this->expressions[$path];
	}

	/**
	 * @return list<RepresentationExpressionSchema>
	 */
	public function getExpressions(): array
	{
		return array_values($this->expressions);
	}

	/**
	 * Field groupings by source path (flat / multi-record provenance).
	 * Memoized until fields or relations change; expressions are not included.
	 *
	 * @return list<RepresentationSource>
	 */
	public function getSources(): array
	{
		return $this->sourcesCache ??= RepresentationSource::fromRepresentationSchema($this);
	}

	/**
	 * Walk nested related schemas by relation-name segments from this root.
	 *
	 * @param list<string> $path
	 */
	public function findRelatedSchemaAt(array $path): ?self
	{
		$current = $this;

		foreach ($path as $segment) {
			if (! $current->hasRelation($segment)) {
				return null;
			}

			$current = $current->getRelation($segment)->getRelatedSchema();
		}

		return $current;
	}

	public function hasPath(string $path): bool
	{
		return $this->hasField($path) || $this->hasRelation($path) || $this->hasExpression($path);
	}

	/**
	 * @return list<string>
	 */
	public function getPaths(): array
	{
		return $this->paths;
	}

	private function assertPathIsAvailable(string $path): void
	{
		if ($this->hasPath($path)) {
			throw new StateException(sprintf("Representation schema already contains path '%s'.", $path));
		}
	}

	private function invalidateSources(): void
	{
		$this->sourcesCache = null;
	}
}
