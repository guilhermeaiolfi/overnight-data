<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation;

use LogicException;
use ON\Data\Query\Expression\AliasedExpression;
use ON\Data\Query\Expression\ValueExpressionInterface;
use ON\Data\Query\QuerySourceInterface;
use ON\Data\Query\SelectQuery;
use ON\Data\Query\SourceMap;
use ON\Data\Schema\RepresentationExpressionSchema;
use ON\Data\Schema\RepresentationFieldSchema;
use ON\Data\Schema\RepresentationSchema;

/**
 * Bidirectional mapping between the schema/branch abstraction and flat SQL
 * result columns (`c0`, `c1`, `c2`, ...) for one fetch execution.
 *
 * Forward: plans schema scalar paths and loader-required fields onto execution
 * queries with collision-free aliases.
 * Reverse: extracts branch-local record values from SQL rows and resolves
 * schema paths (including flat fields delegated to a loaded to-one child).
 */
final class FetchMapping
{
	private int $nextAlias = 0;

	/**
	 * @var array<int, SelectQuery>
	 */
	private array $queries = [];

	/**
	 * Allocated SQL expressions per query: [queryId][sqlAlias] => expression.
	 *
	 * @var array<int, array<string, ValueExpressionInterface>>
	 */
	private array $expressionsByQuery = [];

	/**
	 * Own-field dedup: [branchId][fieldName] => sqlAlias.
	 *
	 * @var array<int, array<string, string>>
	 */
	private array $ownFieldAliases = [];

	/**
	 * Branch extraction map: [branchId][valueKey] => sqlAlias.
	 *
	 * @var array<int, array<string, string>>
	 */
	private array $columnsByBranch = [];

	/**
	 * Schema path lookup: [branchId][schemaPath] => {valueKey, childPath}.
	 *
	 * @var array<int, array<string, array{valueKey: string, childPath: ?list<string>}>>
	 */
	private array $pathBindings = [];

	public function planBranch(LoadBranch $branch, RepresentationSchema $schema): void
	{
		$branch->setRepresentationSchema($schema);
		$branch->setFetchMapping($this);

		foreach ($schema->getPaths() as $path) {
			if ($schema->hasRelation($path)) {
				continue;
			}

			if ($schema->hasField($path)) {
				$this->planField($branch, $schema->getField($path));

				continue;
			}

			if ($schema->hasExpression($path)) {
				$this->planExpression($branch, $schema->getExpression($path));
			}
		}
	}

	public function planRequiredFields(LoadBranch $branch): void
	{
		$branch->setFetchMapping($this);

		foreach ($branch->getRequiredFieldNames() as $fieldName) {
			$this->ensureOwnField($branch, $fieldName);
		}
	}

	/**
	 * Allocate an auxiliary SQL column on a query (for example M2M through-table
	 * keys) without adding it to the branch's value extraction map.
	 */
	public function allocateColumn(SelectQuery $query, ValueExpressionInterface $expression): string
	{
		$alias = $this->nextSqlAlias();
		$this->recordExpression($query, $alias, $expression);

		return $alias;
	}

	/**
	 * Write all allocated columns (`c0`, `c1`, ...) as explicit selections onto
	 * their respective execution queries.
	 */
	public function applyToQueries(): void
	{
		foreach ($this->queries as $queryId => $query) {
			$expressions = $this->expressionsByQuery[$queryId] ?? [];

			if ($expressions === []) {
				continue;
			}

			$query->getSelections()->clear();
			$selects = [];

			foreach ($expressions as $alias => $expression) {
				$selects[] = $expression->as($alias);
			}

			$query->getSelections()->addExplicit($selects);
		}
	}

	/**
	 * Branch column map: valueKey => sqlAlias (`c0`, `c1`, ...).
	 *
	 * @return array<string, string>
	 */
	public function columnsFor(LoadBranch $branch): array
	{
		return $this->columnsByBranch[spl_object_id($branch)] ?? [];
	}

	/**
	 * Extract branch-local values from one SQL result row.
	 *
	 * @param array<string, mixed> $sqlRow
	 * @return array<string, mixed>
	 */
	public function extractRow(LoadBranch $branch, array $sqlRow, bool $includeMissingAsNull = true): array
	{
		$values = [];

		foreach ($this->columnsFor($branch) as $valueKey => $sqlAlias) {
			if (! $includeMissingAsNull && ! array_key_exists($sqlAlias, $sqlRow)) {
				continue;
			}

			$values[$valueKey] = $sqlRow[$sqlAlias] ?? null;
		}

		return $values;
	}

	/**
	 * Resolve how a schema path is read from a branch's values (or a loaded
	 * to-one child destination's values).
	 *
	 * @return array{valueKey: string, childPath: ?list<string>}|null
	 */
	public function resolvePath(LoadBranch $branch, string $path): ?array
	{
		return $this->pathBindings[spl_object_id($branch)][$path] ?? null;
	}

	/**
	 * Allocated SQL aliases and expressions for a query.
	 *
	 * @return array<string, ValueExpressionInterface>
	 */
	public function getExpressionsForQuery(SelectQuery $query): array
	{
		return $this->expressionsByQuery[spl_object_id($query)] ?? [];
	}

	private function planField(LoadBranch $branch, RepresentationFieldSchema $field): void
	{
		$path = $field->getPath();
		$fieldName = $field->getFieldName();
		$sourcePath = $field->getSourcePath();

		if ($sourcePath !== []) {
			$child = $this->loadedToOneChild($branch, $sourcePath);

			if ($child instanceof RelationLoadBranch) {
				$child->requireFields([$fieldName]);
				$this->pathBindings[spl_object_id($branch)][$path] = [
					'valueKey' => $fieldName,
					'childPath' => array_values($sourcePath),
				];

				return;
			}

			$source = $this->resolveFlatSource($branch, $sourcePath);
			$alias = $this->nextSqlAlias();
			$valueKey = '@' . $alias;
			$this->recordExpression($branch->getQuery(), $alias, $source->field($fieldName));
			$this->columnsByBranch[spl_object_id($branch)][$valueKey] = $alias;
			$this->pathBindings[spl_object_id($branch)][$path] = [
				'valueKey' => $valueKey,
				'childPath' => null,
			];

			return;
		}

		$this->ensureOwnField($branch, $fieldName);
		$this->pathBindings[spl_object_id($branch)][$path] = [
			'valueKey' => $fieldName,
			'childPath' => null,
		];
	}

	private function planExpression(LoadBranch $branch, RepresentationExpressionSchema $expressionSchema): void
	{
		$path = $expressionSchema->getPath();
		$expression = $expressionSchema->getExpression();

		if ($expression instanceof AliasedExpression) {
			$expression = $expression->getExpression();
		}

		if (! $expression instanceof ValueExpressionInterface) {
			return;
		}

		$targetSource = $branch->getQueryLocalRelation() ?? $branch->getSource();
		$map = SourceMap::of($branch->getProjectionLevel(), $targetSource);
		$originSource = $expressionSchema->getOriginSource();

		if ($originSource instanceof QuerySourceInterface && $originSource !== $branch->getProjectionLevel()) {
			$map = $map->with($originSource, $targetSource);
		}

		$rebound = $expression->rebind($map);
		$alias = $this->nextSqlAlias();
		$valueKey = '@' . $alias;
		$this->recordExpression($branch->getQuery(), $alias, $rebound);
		$this->columnsByBranch[spl_object_id($branch)][$valueKey] = $alias;
		$this->pathBindings[spl_object_id($branch)][$path] = [
			'valueKey' => $valueKey,
			'childPath' => null,
		];
	}

	private function ensureOwnField(LoadBranch $branch, string $fieldName): string
	{
		$branchId = spl_object_id($branch);

		if (isset($this->ownFieldAliases[$branchId][$fieldName])) {
			return $this->ownFieldAliases[$branchId][$fieldName];
		}

		$alias = $this->nextSqlAlias();
		$this->recordExpression($branch->getQuery(), $alias, $branch->getSource()->field($fieldName));
		$this->ownFieldAliases[$branchId][$fieldName] = $alias;
		$this->columnsByBranch[$branchId][$fieldName] = $alias;

		return $alias;
	}

	/**
	 * @param list<string> $sourcePath
	 */
	private function resolveFlatSource(LoadBranch $branch, array $sourcePath): QuerySourceInterface
	{
		$current = $branch->getQueryLocalRelation() ?? $branch->getQuery();

		foreach ($sourcePath as $segment) {
			if (! $current instanceof SelectQuery && ! $current instanceof RelationRef) {
				throw new LogicException(sprintf(
					"Cannot resolve flat relation segment '%s' from source of type %s.",
					$segment,
					$current::class,
				));
			}

			$current = $current->relation($segment);
		}

		if ($current instanceof RelationRef && $current->hasJoinedSource()) {
			return $current->getJoinedSource();
		}

		return $current;
	}

	/**
	 * Loaded to-one child destination matching a relative source path, or null.
	 *
	 * @param list<string> $relativePath
	 */
	private function loadedToOneChild(LoadBranch $parent, array $relativePath): ?RelationLoadBranch
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

	private function recordExpression(SelectQuery $query, string $sqlAlias, ValueExpressionInterface $expression): void
	{
		$queryId = spl_object_id($query);
		$this->queries[$queryId] = $query;
		$this->expressionsByQuery[$queryId][$sqlAlias] = $expression;
	}

	private function nextSqlAlias(): string
	{
		return 'c' . $this->nextAlias++;
	}
}
