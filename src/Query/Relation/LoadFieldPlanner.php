<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation;

use LogicException;
use ON\Data\Query\Exception\LoadRuntimeException;
use ON\Data\Query\Expression\AliasedExpression;
use ON\Data\Query\Expression\FieldRef;
use ON\Data\Query\Expression\ValueExpressionInterface;
use ON\Data\Query\QuerySourceInterface;
use ON\Data\Query\Selection\SelectionItem;
use ON\Data\Query\SourceMap;
use ON\Data\Schema\RepresentationExpressionSchema;
use ON\Data\Schema\RepresentationFieldSchema;
use ON\Data\Schema\RepresentationSchema;

/**
 * For each schema scalar path at this level, choose where it is fetched, select
 * SQL, and bind schema path → SQL alias.
 *
 * Destinations are existing {@see LoadBranch} nodes (this level, or a loaded to-one child).
 * Does not invent attaches for flats. Paths come only from {@see RepresentationSchema}.
 */
final class LoadFieldPlanner
{
	public function __construct(
		private readonly LoadAliasAllocator $aliases,
		private readonly RepresentationSchema $schema,
	) {
	}

	/**
	 * Emit SQL and bind schema path → SQL alias for this branch.
	 */
	public function bindBranch(LoadBranch $branch): void
	{
		$schema = $this->schemaFor($branch);
		$branch->setRepresentationSchema($schema);
		foreach ($schema->getPaths() as $path) {
			if ($schema->hasRelation($path)) {
				continue;
			}

			$this->bindPath($branch, $schema, $path);
		}

		$this->emitRequiredFields($branch);
	}

	/**
	 * Emit SQL for loader-required join/correlation keys that are not schema paths.
	 */
	public function emitRequiredFields(LoadBranch $branch): void
	{
		foreach ($branch->getRequiredFieldNames() as $fieldName) {
			$this->emitOwnField($branch, $fieldName);
		}
	}

	private function schemaFor(LoadBranch $branch): RepresentationSchema
	{
		if ($branch instanceof RootLoadBranch) {
			return $this->schema;
		}

		if (! $branch instanceof RelationLoadBranch) {
			throw LoadRuntimeException::schemaMissing();
		}

		$path = $branch->getRelationRef()->getPath();
		$node = $this->schema->findRelatedSchemaAt($path);
		if (! $node instanceof RepresentationSchema) {
			throw LoadRuntimeException::schemaNodeMissing($path);
		}

		return $node;
	}

	private function bindPath(
		LoadBranch $branch,
		RepresentationSchema $schema,
		string $path,
	): void {
		if ($branch->hasPathBinding($path)) {
			return;
		}

		if ($schema->hasField($path)) {
			$this->bindFieldSchema($branch, $schema->getField($path));

			return;
		}

		if ($schema->hasExpression($path)) {
			$this->bindExpressionSchema($branch, $schema->getExpression($path));
		}
	}

	private function bindFieldSchema(LoadBranch $branch, RepresentationFieldSchema $field): void
	{
		$outputName = $field->getPath();
		$fieldName = $field->getFieldName();
		$sourcePath = $field->getSourcePath();

		if ($sourcePath !== []) {
			$child = $this->getChildForFlat($branch, $sourcePath);

			if ($child instanceof RelationLoadBranch) {
				$this->emitOwnField($child, $fieldName);
				$child->requireFields([$fieldName]);
				$branch->bindPathToChildDestination($outputName, $sourcePath, $fieldName);

				return;
			}
		}

		$fieldRef = $this->fieldRefFor($branch, $field);
		$sqlKey = $this->selectField(
			$branch,
			$fieldRef,
			$outputName,
			$this->aliasPath($branch),
			$field->isImplicit(),
		);
		$branch->bindPathToLoadKey($outputName, $sqlKey);
	}

	private function bindExpressionSchema(
		LoadBranch $branch,
		RepresentationExpressionSchema $expressionSchema,
	): void {
		$outputName = $expressionSchema->getPath();
		$query = $branch->getQuery();
		$preferred = $this->aliases->aliasForPath($this->aliasPath($branch), $outputName);
		$existing = $query->getSelections()->findBySelectionKey($preferred);

		if ($existing instanceof SelectionItem && $existing->getFieldRef() === null) {
			$branch->bindPathToLoadKey($outputName, $preferred);

			return;
		}

		$sqlKey = $this->aliases->ensureFreeAlias($query, $preferred);
		$expression = $expressionSchema->getExpression();

		if ($expression instanceof AliasedExpression) {
			$expression = $expression->getExpression();
		}

		if (! $expression instanceof ValueExpressionInterface) {
			$branch->bindPathToLoadKey($outputName, $outputName);

			return;
		}

		$rebound = $expression->rebind(SourceMap::of($branch->getProjectionLevel(), $branch->getSource()));
		$this->aliases->emitExpression(
			$query,
			$rebound,
			$sqlKey,
			false,
		);
		$branch->bindPathToLoadKey($outputName, $sqlKey);
	}

	private function fieldRefFor(LoadBranch $branch, RepresentationFieldSchema $field): FieldRef
	{
		$level = $branch->getProjectionLevel();
		$sourcePath = $field->getSourcePath();
		$fieldName = $field->getFieldName();

		if ($sourcePath === []) {
			$fieldRef = $level instanceof RelationRef
				? $level->field($fieldName)
				: $branch->getQuery()->field($fieldName);

			if (! $fieldRef instanceof FieldRef) {
				throw new LogicException(sprintf(
					"Cannot bind schema field '%s' because it does not resolve to a query field.",
					$fieldName,
				));
			}

			return $fieldRef;
		}

		$relation = null;
		foreach ($sourcePath as $segment) {
			$relation = $relation === null
				? ($level instanceof RelationRef ? $level->relation($segment) : $branch->getQuery()->relation($segment))
				: $relation->relation($segment);
		}

		if (! $relation instanceof RelationRef) {
			if ($level instanceof RelationRef) {
				throw LoadRuntimeException::queryNotConfigured($level);
			}

			throw new LogicException(sprintf(
				"Cannot bind schema field '%s' because source path '%s' could not be remapped onto this query level.",
				$fieldName,
				implode('.', $sourcePath),
			));
		}

		return $relation->field($fieldName);
	}

	private function emitOwnField(LoadBranch $branch, string $fieldName): void
	{
		$path = $branch->requireFields([$fieldName])[0];

		if ($branch->hasPathBinding($path) && $branch->childPathForPath($path) === null) {
			return;
		}

		$level = $branch->getProjectionLevel();
		$fieldRef = $level instanceof RelationRef
			? $level->field($fieldName)
			: $branch->getQuery()->field($fieldName);
		$sqlKey = $this->selectField($branch, $fieldRef, $path, $this->aliasPath($branch), true);
		$branch->bindPathToLoadKey($path, $sqlKey);
	}

	/**
	 * Select one column onto the branch query.
	 *
	 * @param list<string> $path
	 */
	public function selectField(
		LoadBranch $branch,
		FieldRef $fieldRef,
		string $outputName,
		array $path,
		bool $sqlOnly = false,
	): string {
		$query = $branch->getQuery();
		$source = $this->resolveSelectionSource($branch, $fieldRef);
		$fieldName = $fieldRef->getField()->getName();
		$preferred = $this->aliases->aliasForPath($path, $outputName);
		$sqlKey = $this->aliases->ensureSqlAlias($query, $source, $fieldName, $preferred);

		if ($this->aliases->isAliasForSameField($query, $sqlKey, $source, $fieldName)) {
			return $sqlKey;
		}

		if ($source === $query && $sqlKey === $fieldName && ! $sqlOnly) {
			$query->select($query->field($fieldName));

			return $sqlKey;
		}

		$this->aliases->emitColumn($query, $source, $fieldName, $sqlKey, $sqlOnly);

		return $sqlKey;
	}

	/**
	 * Path fed to {@see LoadAliasAllocator::aliasForPath()}. Empty on the
	 * query that owns the SELECT (output name). JOIN children on that query
	 * use the destination path (`posts.title` on root, `author.name` on a
	 * SEPARATE posts query).
	 *
	 * @return list<string>
	 */
	private function aliasPath(LoadBranch $branch): array
	{
		return $this->destinationPath($branch);
	}

	/**
	 * Destination path relative to the query that owns this SELECT (not the
	 * global fetch tree). JOIN posts on root → `['posts']`; JOIN author on a
	 * SEPARATE posts query → `['author']`, not `['posts', 'author']`.
	 *
	 * @return list<string>
	 */
	private function destinationPath(LoadBranch $branch): array
	{
		if ($branch->getSource() === $branch->getQuery()) {
			return [];
		}

		$segments = [];
		$current = $branch;

		while ($current instanceof RelationLoadBranch) {
			array_unshift($segments, $current->getRelationRef()->getName());
			$parent = $current->getParent();

			if ($parent->getQuery() !== $current->getQuery() || $parent->getSource() === $parent->getQuery()) {
				break;
			}

			$current = $parent;
		}

		return $segments;
	}

	/**
	 * Query source for a level column: own-level, reused JOIN, or SEPARATE remap.
	 */
	private function resolveSelectionSource(LoadBranch $branch, FieldRef $field): QuerySourceInterface
	{
		$fieldSource = $field->getSource();
		$level = $branch->getProjectionLevel();

		if ($fieldSource === $level) {
			return $branch->getSource();
		}

		if ($fieldSource->getQuery() === $branch->getQuery()) {
			return $this->joinedOrLazySource($fieldSource);
		}

		if (! $fieldSource instanceof RelationRef || ! $fieldSource->isUnder($level)) {
			if ($level instanceof RelationRef) {
				throw LoadRuntimeException::queryNotConfigured($level);
			}

			throw new LogicException('Projection field source must belong to this query level.');
		}

		$relative = $fieldSource->relativeTo($level);
		$relation = null;

		foreach ($relative as $name) {
			$relation = $relation === null
				? $branch->getQuery()->relation($name)
				: $relation->relation($name);
		}

		if (! $relation instanceof RelationRef) {
			if ($level instanceof RelationRef) {
				throw LoadRuntimeException::queryNotConfigured($level);
			}

			throw new LogicException('Projection field source could not be remapped onto this query level.');
		}

		return $this->joinedOrLazySource($relation);
	}

	/**
	 * Reuse an already-joined table; otherwise leave the source lazy so Cycle
	 * joins at SQL compile time (and wraps loader errors as UnsupportedQuery).
	 */
	private function joinedOrLazySource(QuerySourceInterface $source): QuerySourceInterface
	{
		if ($source instanceof RelationRef && $source->hasJoinedSource()) {
			return $source->getJoinedSource();
		}

		return $source;
	}

	/**
	 * Loaded to-one child for a relative path, or null when missing / many-valued
	 * (a scalar flat cannot reuse a collection bag).
	 *
	 * @param list<string> $relativePath
	 */
	private function getChildForFlat(LoadBranch $parent, array $relativePath): ?RelationLoadBranch
	{
		if ($relativePath === []) {
			return null;
		}

		$current = $parent;

		foreach ($relativePath as $segment) {
			$next = null;

			foreach ($current->getChildren() as $child) {
				if (
					$child->getRelationRef()->getName() === $segment
					&& $child->getSelection()->isLoaded()
				) {
					$next = $child;

					break;
				}
			}

			if (! $next instanceof RelationLoadBranch || $next->returnsMany()) {
				return null;
			}

			$current = $next;
		}

		return $current instanceof RelationLoadBranch ? $current : null;
	}
}
