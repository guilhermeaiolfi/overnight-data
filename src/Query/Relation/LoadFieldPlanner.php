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
use ON\Data\Query\Selection\SelectionTag;
use ON\Data\Query\SourceMap;

/**
 * For each level COLUMN, choose where it is fetched, select SQL, and bind output name → SQL alias.
 *
 * Destinations are existing {@see LoadBranch} nodes (this level, or a loaded to-one child).
 * Does not invent attaches for flats.
 */
final class LoadFieldPlanner
{
	public function __construct(
		private readonly LoadAliasAllocator $aliases,
	) {
	}

	/**
	 * Emit SQL and bind output name → SQL alias for this branch's COLUMN fields.
	 */
	public function bindBranch(LoadBranch $branch): void
	{
		foreach ($branch->getSelections()->getByTag(SelectionTag::COLUMN) as $selection) {
			$this->bindColumn($branch, $selection);
		}
	}

	private function bindColumn(
		LoadBranch $branch,
		SelectionItem $selection,
	): void {
		$level = $branch->getProjectionLevel();
		$outputName = $selection->getSelectionKey();
		$fieldRef = $selection->getFieldRef();

		if ($branch->hasPlaceBinding($outputName)) {
			return;
		}

		if (! $fieldRef instanceof FieldRef) {
			$this->bindNonFieldColumn($branch, $selection);

			return;
		}

		$fieldName = $fieldRef->getField()->getName();
		$source = $fieldRef->getSource();
		$isCrossLevelFlat = $source instanceof RelationRef && $source->isUnder($level);

		if ($isCrossLevelFlat) {
			$relative = $source->relativeTo($level);
			$child = $this->getChildForFlat($branch, $relative);

			if ($child instanceof RelationLoadBranch) {
				$this->emitOwnField($child, $fieldName);
				$child->requireFields([$fieldName]);
				$branch->bindPlaceToChildDestination($outputName, $relative, $fieldName);

				return;
			}
		}

		$sqlOnly = $this->isRequiredOnly($branch, $outputName);
		$sqlKey = $this->selectField($branch, $fieldRef, $outputName, $this->aliasPath($branch), $sqlOnly);
		$branch->bindPlaceToLoadKey($outputName, $sqlKey);
	}

	private function bindNonFieldColumn(LoadBranch $branch, SelectionItem $selection): void
	{
		$outputName = $selection->getSelectionKey();
		$query = $branch->getQuery();
		$preferred = $this->aliases->aliasForPath($this->aliasPath($branch), $outputName);
		$existing = $query->getSelections()->findBySelectionKey($preferred);

		if ($existing instanceof SelectionItem && $existing->getFieldRef() === null) {
			$branch->bindPlaceToLoadKey($outputName, $preferred);

			return;
		}

		$sqlKey = $this->aliases->ensureFreeAlias($query, $preferred);
		$expression = $selection->getExpression();

		if ($expression instanceof AliasedExpression) {
			$expression = $expression->getExpression();
		}

		if (! $expression instanceof ValueExpressionInterface) {
			$branch->bindPlaceToLoadKey($outputName, $outputName);

			return;
		}

		$rebound = $expression->rebind(SourceMap::of($branch->getProjectionLevel(), $branch->getSource()));
		$this->aliases->emitExpression(
			$query,
			$rebound,
			$sqlKey,
			$this->isRequiredOnly($branch, $outputName),
		);
		$branch->bindPlaceToLoadKey($outputName, $sqlKey);
	}

	private function emitOwnField(LoadBranch $branch, string $fieldName): void
	{
		$placeKey = $branch->requireFields([$fieldName])[0];

		if ($branch->hasPlaceBinding($placeKey) && $branch->childPathForPlace($placeKey) === null) {
			return;
		}

		$level = $branch->getProjectionLevel();
		$fieldRef = $level instanceof RelationRef
			? $level->field($fieldName)
			: $branch->getQuery()->field($fieldName);
		$sqlOnly = $this->isRequiredOnly($branch, $placeKey);
		$sqlKey = $this->selectField($branch, $fieldRef, $placeKey, $this->aliasPath($branch), $sqlOnly);
		$branch->bindPlaceToLoadKey($placeKey, $sqlKey);
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
	 * Path fed to {@see LoadAliasAllocator::aliasForPath()}. Empty on owned
	 * SELECT and on SEPARATE queries (output name; collisions get a suffix).
	 * JOIN on the original root uses the destination path (`posts.title`).
	 *
	 * @return list<string>
	 */
	private function aliasPath(LoadBranch $branch): array
	{
		return $this->usesDottedAliases($branch) ? $this->destinationPath($branch) : [];
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
	 * Dotted column aliases belong on the original root query, where JOIN
	 * columns share a row with parent fields. SEPARATE queries (and their
	 * windowed derived wrappers) use output names; collisions get a suffix.
	 */
	private function usesDottedAliases(LoadBranch $branch): bool
	{
		$owner = $branch;

		while ($owner instanceof RelationLoadBranch && $owner->getSource() !== $owner->getQuery()) {
			$owner = $owner->getParent();
		}

		return ! $owner instanceof RelationLoadBranch;
	}

	private function isRequiredOnly(LoadBranch $branch, string $outputName): bool
	{
		$selection = $branch->getSelections()->findBySelectionKey($outputName);

		return ! $selection instanceof SelectionItem
			|| $selection->hasTag(SelectionTag::INTERNAL)
			|| ! $selection->isExplicit();
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
