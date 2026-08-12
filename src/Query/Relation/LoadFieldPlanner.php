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
		private readonly LoadAliasAllocator $aliases,
	) {
	}

	/**
	 * Bind this branch's COLUMN fields: select SQL and place→load binds.
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
		$placeKey = $selection->getSelectionKey();
		$fieldRef = $selection->getFieldRef();

		if ($branch->hasPlaceBinding($placeKey)) {
			return;
		}

		if (! $fieldRef instanceof FieldRef) {
			$branch->bindPlaceToLoadKey($placeKey, $placeKey);

			return;
		}

		$fieldName = $fieldRef->getField()->getName();
		$source = $fieldRef->getSource();
		$isCrossLevelFlat = $source instanceof RelationRef && $source->isUnder($level);

		if ($isCrossLevelFlat) {
			$relative = $source->relativeTo($level);
			$child = $this->getChildForFlat($branch, $relative);

			if ($child instanceof RelationLoadBranch) {
				$loadKeys = $this->runtime->requireFields($child, [$fieldName]);
				$branch->bindPlaceToChildDestination($placeKey, $relative, $loadKeys[0] ?? $fieldName);

				return;
			}
		}

		if ($source === $level) {
			$this->runtime->requireFields($branch, [$fieldName]);

			return;
		}

		$path = $source instanceof RelationRef
			? $source->getPath()
			: ($level instanceof RelationRef ? $level->getPath() : []);
		$loadKey = $this->getLoadKey($level, $selection, $fieldRef, $fieldName);
		$sqlKey = $this->selectField($branch, $fieldRef, $placeKey, $loadKey, $path);
		$branch->bindPlaceToLoadKey($placeKey, $sqlKey);
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
		$source = $this->resolveSelectionSource($branch, $fieldRef);
		$fieldName = $fieldRef->getField()->getName();
		$ownsSelect = $branch->getSource() === $query;
		$preferred = $this->aliases->chooseSqlAlias($loadKey, $fieldName, $path, $ownsSelect);
		$sqlKey = $this->aliases->ensureSqlAlias($query, $source, $fieldName, $preferred);

		if ($sqlOnly) {
			if (! $this->aliases->isAliasForSameField($query, $sqlKey, $source, $fieldName)) {
				$this->aliases->emitColumn($query, $source, $fieldName, $sqlKey, sqlOnly: true);
			}

			return $sqlKey;
		}

		// Authoring place alias already on this query (typical root): keep it and
		// add a load-local SQL column for the parser.
		if (
			$placeKey !== $sqlKey
			&& $query->getSelections()->hasSelectionKey($placeKey)
		) {
			if (! $this->aliases->isAliasForSameField($query, $sqlKey, $source, $fieldName)) {
				$this->aliases->emitColumn($query, $source, $fieldName, $sqlKey, sqlOnly: true);
			}

			return $sqlKey;
		}

		if ($this->aliases->isAliasForSameField($query, $sqlKey, $source, $fieldName)) {
			return $sqlKey;
		}

		if ($source === $query && $sqlKey === $fieldName) {
			$query->select($query->field($fieldName));

			return $sqlKey;
		}

		$this->aliases->emitColumn($query, $source, $fieldName, $sqlKey, sqlOnly: false);

		return $sqlKey;
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

		if (! $fieldSource instanceof RelationRef || ! $fieldSource->isUnder($level)) {
			if ($level instanceof RelationRef) {
				throw LoadRuntimeException::queryNotConfigured($level);
			}

			throw new LogicException('Projection field source must belong to this query level.');
		}

		if ($fieldSource->getQuery() === $branch->getQuery()) {
			return $fieldSource->getJoinedSource();
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
	 * Load-local parser key for a level column.
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
