<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation;

use LogicException;
use ON\Data\Query\Exception\LoadRuntimeException;
use ON\Data\Query\Expression\FieldRef;
use ON\Data\Query\QuerySourceInterface;
use ON\Data\Query\Selection\SelectionItem;
use ON\Data\Query\Selection\SelectionTag;
use ON\Data\Query\SelectQuery;

/**
 * For each place-level COLUMN, choose where it is fetched, select SQL, and bind place→load.
 *
 * Destinations are existing {@see LoadBranch} nodes (this level, or a loaded to-one child).
 * Does not invent attaches for flats.
 */
final class LoadFieldPlanner
{
	public function __construct(
		private readonly LoadRuntime $runtime,
	) {
	}

	/**
	 * Bind this branch's COLUMN fields: select SQL, set parser aliases, place→load binds.
	 *
	 * @param bool $includeCrossLevelFlats when false, omit FieldRefs whose source is under
	 *        this level (early root pass before child destinations exist)
	 */
	public function bindBranch(LoadBranch $branch, bool $includeCrossLevelFlats = true): void
	{
		$aliases = [];

		foreach ($branch->getSelections()->getByTag(SelectionTag::COLUMN) as $selection) {
			$alias = $this->bindColumn($branch, $selection, $includeCrossLevelFlats);

			if ($alias !== null) {
				$aliases[] = $alias;
			}
		}

		if ($branch->hasNode()) {
			$branch->getPublicNode()->setValueAliases($aliases);
		}
	}

	/**
	 * Bind one COLUMN selection. Returns the load-local parser alias for local selects;
	 * null when skipped or fetched from a child destination.
	 */
	private function bindColumn(
		LoadBranch $branch,
		SelectionItem $selection,
		bool $includeCrossLevelFlats,
	): ?string {
		$level = $branch->getProjectionLevel();
		$placeKey = $selection->getSelectionKey();
		$fieldRef = $selection->getFieldRef();

		if ($branch->hasPlaceBinding($placeKey)) {
			return $branch->childPathForPlace($placeKey) === null
				? $branch->loadKeyForPlace($placeKey)
				: null;
		}

		if (! $fieldRef instanceof FieldRef) {
			$branch->bindPlaceToLoadKey($placeKey, $placeKey);

			return $placeKey;
		}

		$fieldName = $fieldRef->getField()->getName();
		$source = $fieldRef->getSource();
		$isCrossLevelFlat = $source instanceof RelationRef && $source->isUnder($level);

		if ($isCrossLevelFlat && ! $includeCrossLevelFlats) {
			return null;
		}

		if ($isCrossLevelFlat) {
			$relative = $source->relativeTo($level);
			$child = $this->getChildForFlat($branch, $relative);

			if ($child instanceof RelationLoadBranch) {
				$loadKeys = $this->runtime->requireFields($child, [$fieldName]);
				$branch->bindPlaceToChildDestination($placeKey, $relative, $loadKeys[0] ?? $fieldName);

				return null;
			}
		}

		if ($source === $level) {
			$loadKeys = $this->runtime->requireFields($branch, [$fieldName]);

			return $loadKeys[0] ?? $fieldName;
		}

		$path = $source instanceof RelationRef
			? $source->getPath()
			: ($level instanceof RelationRef ? $level->getPath() : []);
		$loadKey = $this->getLoadKey($level, $selection, $fieldRef, $fieldName);
		$sqlKey = $this->selectField($branch, $fieldRef, $placeKey, $loadKey, $path);
		$branch->bindPlaceToLoadKey($placeKey, $sqlKey);

		return $sqlKey;
	}

	/**
	 * Select one own-level / flat-under-this-destination column onto the branch query.
	 *
	 * @param list<string> $path
	 */
	public function selectField(
		LoadBranch $branch,
		FieldRef $fieldRef,
		string $placeKey,
		string $loadKey,
		array $path,
		bool $sqlOnly = false,
	): string {
		$query = $branch->getQuery();
		$source = $this->getSelectionSource($branch, $fieldRef);
		$fieldName = $fieldRef->getField()->getName();
		$ownsSelect = $branch->getSource() === $query;
		$preferred = $this->getLoadAlias($loadKey, $fieldName, $path, $ownsSelect);
		$sqlKey = $this->resolveSqlKey($query, $source, $fieldName, $preferred);

		if ($sqlOnly) {
			if (! $this->aliasProvidesField($query, $sqlKey, $source, $fieldName)) {
				$this->selectAs($query, $source, $fieldName, $sqlKey, sqlOnly: true);
			}

			return $sqlKey;
		}

		// Authoring place alias already on this query (typical root): keep it and
		// add a load-local SQL column for the parser.
		if (
			$placeKey !== $sqlKey
			&& $query->getSelections()->hasSelectionKey($placeKey)
		) {
			if (! $this->aliasProvidesField($query, $sqlKey, $source, $fieldName)) {
				$this->selectAs($query, $source, $fieldName, $sqlKey, sqlOnly: true);
			}

			return $sqlKey;
		}

		if ($this->aliasProvidesField($query, $sqlKey, $source, $fieldName)) {
			return $sqlKey;
		}

		if ($source === $query && $sqlKey === $fieldName) {
			$query->select($query->field($fieldName));

			return $sqlKey;
		}

		$this->selectAs($query, $source, $fieldName, $sqlKey, sqlOnly: false);

		return $sqlKey;
	}

	/**
	 * Prefer the planned load key when safe; otherwise a path-based JOIN alias.
	 *
	 * Safe: branch owns the SELECT (SEPARATE / root), or the key is already
	 * namespaced (flats like author__name, existing l_* aliases).
	 *
	 * @param list<string> $path
	 */
	private function getLoadAlias(
		string $loadKey,
		string $fieldName,
		array $path,
		bool $ownsSelect,
	): string {
		$preferred = $loadKey !== '' ? $loadKey : $this->runtime->getJoinedAlias($path, $fieldName);

		if ($ownsSelect || $preferred !== $fieldName) {
			return $preferred;
		}

		// JOIN onto a shared parent query with a bare field name — avoid collisions.
		return $this->runtime->getJoinedAlias($path, $fieldName);
	}

	/**
	 * Use $preferred when free or already the same source field; otherwise allocate
	 * a collision-safe private alias (public l_* names must not steal unrelated columns).
	 */
	private function resolveSqlKey(
		SelectQuery $query,
		QuerySourceInterface $source,
		string $fieldName,
		string $preferred,
	): string {
		if (
			! $this->aliasOccupied($query, $preferred)
			|| $this->aliasProvidesField($query, $preferred, $source, $fieldName)
		) {
			return $preferred;
		}

		return $this->allocateUniqueAlias($query, $source, $fieldName, $preferred);
	}

	private function selectAs(
		SelectQuery $query,
		QuerySourceInterface $source,
		string $fieldName,
		string $alias,
		bool $sqlOnly,
	): void {
		if ($this->aliasProvidesField($query, $alias, $source, $fieldName)) {
			return;
		}

		if ($this->aliasOccupied($query, $alias)) {
			throw new LogicException(
				'Load alias "' . $alias . '" is occupied by a different selection; resolveSqlKey should have allocated a free key.',
			);
		}

		$expression = $source->field($fieldName)->as($alias);

		if ($sqlOnly) {
			$query->getSelections()->add(
				$expression,
				[SelectionTag::SQL_ONLY, SelectionTag::COLUMN],
			);

			return;
		}

		$query->select($expression);
	}

	private function aliasOccupied(SelectQuery $query, string $alias): bool
	{
		$selections = $query->getSelections();

		return $selections->hasSelectionKey($alias) || $selections->hasNamedExpression($alias);
	}

	private function aliasProvidesField(
		SelectQuery $query,
		string $alias,
		QuerySourceInterface $source,
		string $fieldName,
	): bool {
		$existing = $query->getSelections()->findBySelectionKey($alias);

		if ($existing === null) {
			return false;
		}

		$fieldRef = $existing->getFieldRef();

		if (! $fieldRef instanceof FieldRef || $fieldRef->getField()->getName() !== $fieldName) {
			return false;
		}

		return $this->sourcesEquivalent($fieldRef->getSource(), $source);
	}

	private function sourcesEquivalent(QuerySourceInterface $left, QuerySourceInterface $right): bool
	{
		if ($left === $right) {
			return true;
		}

		// FieldRefs authored on a RelationRef share the loader Join after attach.
		if ($left instanceof RelationRef && $left->hasJoinedSource() && $left->getJoinedSource() === $right) {
			return true;
		}

		if ($right instanceof RelationRef && $right->hasJoinedSource() && $right->getJoinedSource() === $left) {
			return true;
		}

		if ($left instanceof RelationRef && $right instanceof RelationRef) {
			return $left->getQuery() === $right->getQuery()
				&& $left->getPath() === $right->getPath();
		}

		return false;
	}

	private function allocateUniqueAlias(
		SelectQuery $query,
		QuerySourceInterface $source,
		string $fieldName,
		string $preferred,
	): string {
		$candidate = $preferred;
		$suffix = 2;

		while ($this->aliasOccupied($query, $candidate)) {
			if ($this->aliasProvidesField($query, $candidate, $source, $fieldName)) {
				return $candidate;
			}

			$candidate = $preferred . '_' . $suffix;
			++$suffix;
		}

		return $candidate;
	}

	/**
	 * Query source that provides a level column selection.
	 * Own-level fields use the branch source; flat related fields join under this level.
	 */
	private function getSelectionSource(LoadBranch $branch, FieldRef $field): QuerySourceInterface
	{
		$fieldSource = $field->getSource();
		$level = $branch->getProjectionLevel();

		if ($fieldSource === $level) {
			return $branch->getSource();
		}

		if (! $fieldSource instanceof RelationRef || ! $fieldSource->isUnder($level)) {
			if ($level instanceof RelationRef) {
				throw LoadRuntimeException::queryNotConfigured($level);
			}

			throw new LogicException('Projection field source must belong to this query level.');
		}

		// JOIN attachment (and other same-query graphs): reuse the original relation joins.
		if ($fieldSource->getQuery() === $branch->getQuery()) {
			return $fieldSource->getJoinedSource();
		}

		// SEPARATE query rooted at this level: remap the relative relation path.
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

		return $relation->getJoinedSource();
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

	/**
	 * Load-local parser key for a level column (proposal 0003).
	 * INTERNAL keys stay as planned; own fields use the field name; flats use a
	 * relative path key — place aliases are applied later by assemble.
	 */
	private function getLoadKey(
		SelectQuery|RelationRef $level,
		SelectionItem $selection,
		FieldRef $fieldRef,
		string $fieldName,
	): string {
		if ($selection->hasTag(SelectionTag::INTERNAL)) {
			return $selection->getSelectionKey();
		}

		if ($fieldRef->getSource() === $level) {
			return $fieldName;
		}

		$source = $fieldRef->getSource();
		if (! $source instanceof RelationRef || ! $source->isUnder($level)) {
			return $fieldName;
		}

		return implode('__', [...$source->relativeTo($level), $fieldName]);
	}
}
