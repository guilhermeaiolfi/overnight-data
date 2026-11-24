<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation;

use LogicException;
use ON\Data\Query\Expression\AliasedExpression;
use ON\Data\Query\Expression\ValueExpressionInterface;
use ON\Data\Query\QuerySourceInterface;
use ON\Data\Query\SelectQuery;
use ON\Data\Query\SourceMapBuilder;
use ON\Data\Schema\RepresentationExpressionSchema;
use ON\Data\Schema\RepresentationFieldSchema;
use ON\Data\Schema\RepresentationSchema;

/**
 * Explicit SQL column table for one fetch execution (`c0`, `c1`, `c2`, ...).
 *
 * Maps every allocated SQL alias to its owning query, branch, parser bag key,
 * optional schema path, role ({@see ColumnRole}), and SQL expression. Also
 * tracks schema paths delegated to a loaded to-one child destination.
 */
final class ColumnIndex
{
	private int $nextAlias = 0;

	/**
	 * @var array<string, ColumnEntry>
	 */
	private array $entries = [];

	/**
	 * @var array<int, SelectQuery>
	 */
	private array $queries = [];

	/**
	 * @var array<int, list<ColumnEntry>>
	 */
	private array $entriesByQuery = [];

	/**
	 * Own-field dedup: [branchId][fieldName] => sqlAlias.
	 *
	 * @var array<int, array<string, string>>
	 */
	private array $ownFieldAliases = [];

	/**
	 * Parser-node remap: [branchId][bagKey] => sqlAlias.
	 *
	 * @var array<int, array<string, string>>
	 */
	private array $remapByBranch = [];

	/**
	 * Schema path lookup: [branchId][schemaPath] => {bagKey, childPath}.
	 *
	 * @var array<int, array<string, array{bagKey: string, childPath: ?list<string>}>>
	 */
	private array $pathBindings = [];

	public function planBranch(LoadBranch $branch, RepresentationSchema $schema): void
	{
		$branch->setRepresentationSchema($schema);
		$branch->setColumnIndex($this);

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
		$branch->setColumnIndex($this);

		foreach ($branch->getRequiredFieldNames() as $fieldName) {
			$this->ensureOwnField($branch, $fieldName, ColumnRole::JoinKey, null);
		}
	}

	/**
	 * Allocate an auxiliary SQL column on a query (for example M2M through-table
	 * keys) without adding it to the branch's target node remap.
	 */
	public function allocateAuxiliary(
		SelectQuery $query,
		LoadBranch $branch,
		ValueExpressionInterface $expression,
		ColumnRole $role = ColumnRole::JoinKey,
	): string {
		$alias = $this->nextSqlAlias();
		$entry = new ColumnEntry(
			$alias,
			$query,
			$branch,
			'@' . $alias,
			null,
			$role,
			$expression,
		);
		$this->recordEntry($entry);

		return $alias;
	}

	/**
	 * Write all allocated columns (`c0`, `c1`, ...) as explicit selections onto
	 * their respective execution queries.
	 */
	public function applyToQueries(): void
	{
		foreach ($this->queries as $queryId => $query) {
			$entries = $this->entriesByQuery[$queryId] ?? [];

			if ($entries === []) {
				continue;
			}

			$query->getSelections()->clear();
			$selects = [];

			foreach ($entries as $entry) {
				$selects[] = $entry->getExpression()->as($entry->getSqlAlias());
			}

			$query->getSelections()->addExplicit($selects);
		}
	}

	/**
	 * Parser-node column remap for one branch: bagKey => sqlAlias.
	 *
	 * @return array<string, string>
	 */
	public function remapColumnsFor(LoadBranch $branch): array
	{
		return $this->remapByBranch[spl_object_id($branch)] ?? [];
	}

	/**
	 * Resolve how a schema path is read from a branch's parsed bag (or a loaded
	 * to-one child destination's bag).
	 *
	 * @return array{bagKey: string, childPath: ?list<string>}|null
	 */
	public function resolvePath(LoadBranch $branch, string $path): ?array
	{
		return $this->pathBindings[spl_object_id($branch)][$path] ?? null;
	}

	/**
	 * @return list<ColumnEntry>
	 */
	public function getEntries(): array
	{
		return array_values($this->entries);
	}

	/**
	 * @return list<ColumnEntry>
	 */
	public function getEntriesForQuery(SelectQuery $query): array
	{
		return $this->entriesByQuery[spl_object_id($query)] ?? [];
	}

	public function getEntry(string $sqlAlias): ?ColumnEntry
	{
		return $this->entries[$sqlAlias] ?? null;
	}

	private function planField(LoadBranch $branch, RepresentationFieldSchema $field): void
	{
		$path = $field->getPath();
		$fieldName = $field->getFieldName();
		$sourcePath = $field->getSourcePath();
		$role = $field->isImplicit() ? ColumnRole::Identity : ColumnRole::Public;

		if ($sourcePath !== []) {
			$child = $this->loadedToOneChild($branch, $sourcePath);

			if ($child instanceof RelationLoadBranch) {
				$child->requireFields([$fieldName]);
				$this->pathBindings[spl_object_id($branch)][$path] = [
					'bagKey' => $fieldName,
					'childPath' => array_values($sourcePath),
				];

				return;
			}

			$source = $this->resolveFlatSource($branch, $sourcePath);
			$alias = $this->nextSqlAlias();
			$bagKey = '@' . $alias;
			$entry = new ColumnEntry(
				$alias,
				$branch->getQuery(),
				$branch,
				$bagKey,
				$path,
				$role,
				$source->field($fieldName),
			);
			$this->recordEntry($entry);
			$this->remapByBranch[spl_object_id($branch)][$bagKey] = $alias;
			$this->pathBindings[spl_object_id($branch)][$path] = [
				'bagKey' => $bagKey,
				'childPath' => null,
			];

			return;
		}

		$this->ensureOwnField($branch, $fieldName, $role, $path);
		$this->pathBindings[spl_object_id($branch)][$path] = [
			'bagKey' => $fieldName,
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
		$mapBuilder = new SourceMapBuilder();
		$mapBuilder->map($branch->getProjectionLevel(), $targetSource);
		$originSource = $expressionSchema->getOriginSource();

		if ($originSource instanceof QuerySourceInterface && $originSource !== $branch->getProjectionLevel()) {
			$mapBuilder->map($originSource, $targetSource);
		}

		$rebound = $expression->rebind($mapBuilder->seal());
		$alias = $this->nextSqlAlias();
		$bagKey = '@' . $alias;
		$entry = new ColumnEntry(
			$alias,
			$branch->getQuery(),
			$branch,
			$bagKey,
			$path,
			ColumnRole::Public,
			$rebound,
		);
		$this->recordEntry($entry);
		$this->remapByBranch[spl_object_id($branch)][$bagKey] = $alias;
		$this->pathBindings[spl_object_id($branch)][$path] = [
			'bagKey' => $bagKey,
			'childPath' => null,
		];
	}

	private function ensureOwnField(
		LoadBranch $branch,
		string $fieldName,
		ColumnRole $role,
		?string $schemaPath,
	): string {
		$branchId = spl_object_id($branch);

		if (isset($this->ownFieldAliases[$branchId][$fieldName])) {
			return $this->ownFieldAliases[$branchId][$fieldName];
		}

		$alias = $this->nextSqlAlias();
		$entry = new ColumnEntry(
			$alias,
			$branch->getQuery(),
			$branch,
			$fieldName,
			$schemaPath,
			$role,
			$branch->getSource()->field($fieldName),
		);
		$this->recordEntry($entry);
		$this->ownFieldAliases[$branchId][$fieldName] = $alias;
		$this->remapByBranch[$branchId][$fieldName] = $alias;

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

	private function recordEntry(ColumnEntry $entry): void
	{
		$query = $entry->getQuery();
		$queryId = spl_object_id($query);
		$this->entries[$entry->getSqlAlias()] = $entry;
		$this->queries[$queryId] = $query;
		$this->entriesByQuery[$queryId][] = $entry;
	}

	private function nextSqlAlias(): string
	{
		return 'c' . $this->nextAlias++;
	}
}
