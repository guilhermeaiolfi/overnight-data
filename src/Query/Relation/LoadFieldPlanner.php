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
	 * Bind this branch's COLUMN fields: plan fetches, select SQL, set parser aliases.
	 *
	 * @param bool $includeCrossLevelFlats when false, omit FieldRefs whose source is under
	 *        this level (early root pass before child destinations exist)
	 */
	public function bindBranch(LoadBranch $branch, bool $includeCrossLevelFlats = true): void
	{
		$fetches = $this->getFetches($branch, $includeCrossLevelFlats);
		$aliases = $this->applyFetches($branch, $fetches);

		if ($branch->hasNode()) {
			$branch->getPublicNode()->setValueAliases($aliases);
		}
	}

	/**
	 * @return list<array{
	 *     placeKey: string,
	 *     selection: SelectionItem,
	 *     fieldRef: ?FieldRef,
	 *     mode: 'local'|'child'|'skip',
	 *     childPath?: list<string>,
	 *     child?: RelationLoadBranch,
	 *     fieldName?: string,
	 * }>
	 */
	private function getFetches(LoadBranch $branch, bool $includeCrossLevelFlats): array
	{
		$level = $branch->getProjectionLevel();
		$fetches = [];

		foreach ($branch->getSelections()->getByTag(SelectionTag::COLUMN) as $selection) {
			$placeKey = $selection->getSelectionKey();
			$fieldRef = $selection->getFieldRef();

			if (! $fieldRef instanceof FieldRef) {
				$fetches[] = [
					'placeKey' => $placeKey,
					'selection' => $selection,
					'fieldRef' => null,
					'mode' => 'local',
				];

				continue;
			}

			$fieldName = $fieldRef->getField()->getName();
			$source = $fieldRef->getSource();
			$isCrossLevelFlat = $source instanceof RelationRef && $source->isUnder($level);

			if ($isCrossLevelFlat && ! $includeCrossLevelFlats) {
				$fetches[] = [
					'placeKey' => $placeKey,
					'selection' => $selection,
					'fieldRef' => $fieldRef,
					'mode' => 'skip',
					'fieldName' => $fieldName,
				];

				continue;
			}

			if ($isCrossLevelFlat) {
				$relative = $source->relativeTo($level);
				$child = $this->getChildForFlat($branch, $relative);

				if ($child instanceof RelationLoadBranch) {
					$fetches[] = [
						'placeKey' => $placeKey,
						'selection' => $selection,
						'fieldRef' => $fieldRef,
						'mode' => 'child',
						'childPath' => $relative,
						'child' => $child,
						'fieldName' => $fieldName,
					];

					continue;
				}
			}

			$fetches[] = [
				'placeKey' => $placeKey,
				'selection' => $selection,
				'fieldRef' => $fieldRef,
				'mode' => 'local',
				'fieldName' => $fieldName,
			];
		}

		return $fetches;
	}

	/**
	 * @param list<array{
	 *     placeKey: string,
	 *     selection: SelectionItem,
	 *     fieldRef: ?FieldRef,
	 *     mode: 'local'|'child'|'skip',
	 *     childPath?: list<string>,
	 *     child?: RelationLoadBranch,
	 *     fieldName?: string,
	 * }> $fetches
	 * @return list<string> load-local parser aliases for this place branch’s node
	 */
	private function applyFetches(LoadBranch $branch, array $fetches): array
	{
		$level = $branch->getProjectionLevel();
		$aliases = [];

		foreach ($fetches as $fetch) {
			$placeKey = $fetch['placeKey'];

			if ($fetch['mode'] === 'skip') {
				continue;
			}

			if ($fetch['mode'] === 'child') {
				$child = $fetch['child'] ?? null;
				$fieldName = $fetch['fieldName'] ?? '';
				$childPath = $fetch['childPath'] ?? [];

				if (! $child instanceof RelationLoadBranch || $fieldName === '' || $childPath === []) {
					throw new LogicException('Child fetch is incomplete.');
				}

				$loadKeys = $this->runtime->requireFields($child, [$fieldName]);
				$branch->bindPlaceToChildDestination($placeKey, $childPath, $loadKeys[0] ?? $fieldName);

				continue;
			}

			$fieldRef = $fetch['fieldRef'];

			if (! $fieldRef instanceof FieldRef) {
				$branch->bindPlaceToLoadKey($placeKey, $placeKey);
				$aliases[] = $placeKey;

				continue;
			}

			$fieldName = $fetch['fieldName'] ?? $fieldRef->getField()->getName();
			$source = $fieldRef->getSource();
			$path = $source instanceof RelationRef
				? $source->getPath()
				: ($level instanceof RelationRef ? $level->getPath() : []);
			$loadKey = $this->getLoadKey($level, $fetch['selection'], $fieldRef, $fieldName);
			$sqlKey = $this->selectField($branch, $fieldRef, $placeKey, $loadKey, $path);
			$branch->bindPlaceToLoadKey($placeKey, $sqlKey);
			$aliases[] = $sqlKey;
		}

		return $aliases;
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
	): string {
		$query = $branch->getQuery();
		$source = $this->getSelectionSource($branch, $fieldRef);
		$fieldName = $fieldRef->getField()->getName();
		$ownsSelect = $branch->getSource() === $query;
		$sqlKey = $this->getLoadAlias($loadKey, $fieldName, $path, $ownsSelect);

		// Authoring place alias already on this query (typical root): keep it and
		// add a load-local SQL column for the parser.
		if (
			$placeKey !== $sqlKey
			&& $query->getSelections()->hasSelectionKey($placeKey)
		) {
			$this->selectAs($query, $source, $fieldName, $sqlKey, sqlOnly: true);

			return $sqlKey;
		}

		if (
			$query->getSelections()->hasSelectionKey($sqlKey)
			|| $query->getSelections()->hasNamedExpression($sqlKey)
			|| (
				$placeKey === $sqlKey
				&& $query->getSelections()->hasSelectionKey($placeKey)
			)
		) {
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

	private function selectAs(
		SelectQuery $query,
		QuerySourceInterface $source,
		string $fieldName,
		string $alias,
		bool $sqlOnly,
	): void {
		if (
			$query->getSelections()->hasSelectionKey($alias)
			|| $query->getSelections()->hasNamedExpression($alias)
		) {
			return;
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
