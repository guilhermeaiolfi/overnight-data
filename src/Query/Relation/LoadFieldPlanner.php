<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation;

use LogicException;
use ON\Data\Query\Exception\LoadRuntimeException;
use ON\Data\Query\Expression\AliasedExpression;
use ON\Data\Query\Expression\FieldRef;
use ON\Data\Query\QuerySourceInterface;
use ON\Data\Query\Selection\SelectionItem;
use ON\Data\Query\Selection\SelectionTag;
use ON\Data\Query\SelectQuery;

/**
 * Assigns each place-level COLUMN field to a fetch destination, then emits SQL / binds.
 *
 * Destinations are existing {@see LoadBranch} nodes (this level, or a loaded to-one child).
 * Does not invent attaches for flats.
 *
 * @phpstan-type FetchHome array{
 *     placeKey: string,
 *     selection: SelectionItem,
 *     fieldRef: ?FieldRef,
 *     mode: 'local'|'child'|'skip',
 *     childPath?: list<string>,
 *     child?: RelationLoadBranch,
 *     fieldName?: string,
 * }
 */
final class LoadFieldPlanner
{
	public function __construct(
		private readonly LoadRuntime $runtime,
	) {
	}

	/**
	 * Group this place level's COLUMN fields by fetch destination and bind/emit them.
	 *
	 * @param bool $includeCrossLevelFlats when false, defer FieldRefs whose source is under
	 *        this level (early root pass before child destinations exist)
	 */
	public function bindLevel(LoadBranch $branch, bool $includeCrossLevelFlats = true): void
	{
		$homes = $this->assignFetchHomes($branch, $includeCrossLevelFlats);
		$aliases = $this->emitFetchHomes($branch, $homes);

		if ($branch->hasNode()) {
			$branch->getPublicNode()->setValueAliases($aliases);
		}
	}

	/**
	 * Decide fetch home for each COLUMN on the place-level branch.
	 *
	 * @return list<FetchHome>
	 */
	private function assignFetchHomes(LoadBranch $branch, bool $includeCrossLevelFlats): array
	{
		$level = $branch->getProjectionLevel();
		$homes = [];

		foreach ($branch->getSelections()->getByTag(SelectionTag::COLUMN) as $selection) {
			$placeKey = $selection->getSelectionKey();
			$fieldRef = $this->columnFieldRef($selection);

			if (! $fieldRef instanceof FieldRef) {
				$homes[] = [
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
				$homes[] = [
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
				$child = $this->findLoadedToOneChildDestination($branch, $relative);

				if ($child instanceof RelationLoadBranch) {
					$homes[] = [
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

			$homes[] = [
				'placeKey' => $placeKey,
				'selection' => $selection,
				'fieldRef' => $fieldRef,
				'mode' => 'local',
				'fieldName' => $fieldName,
			];
		}

		return $homes;
	}

	/**
	 * @param list<FetchHome> $homes
	 * @return list<string> load-local parser aliases for this place branch’s node
	 */
	private function emitFetchHomes(LoadBranch $branch, array $homes): array
	{
		$level = $branch->getProjectionLevel();
		$aliases = [];

		foreach ($homes as $home) {
			$placeKey = $home['placeKey'];

			if ($home['mode'] === 'skip') {
				continue;
			}

			if ($home['mode'] === 'child') {
				$child = $home['child'] ?? null;
				$fieldName = $home['fieldName'] ?? '';
				$childPath = $home['childPath'] ?? [];

				if (! $child instanceof RelationLoadBranch || $fieldName === '' || $childPath === []) {
					throw new LogicException('Child fetch home is incomplete.');
				}

				$loadKeys = $this->runtime->requireFields($child, [$fieldName]);
				$branch->bindPlaceToChildDestination($placeKey, $childPath, $loadKeys[0] ?? $fieldName);

				continue;
			}

			$fieldRef = $home['fieldRef'];

			if (! $fieldRef instanceof FieldRef) {
				$branch->bindPlaceToLoadKey($placeKey, $placeKey);
				$aliases[] = $placeKey;

				continue;
			}

			$fieldName = $home['fieldName'] ?? $fieldRef->getField()->getName();
			$source = $fieldRef->getSource();
			$path = $source instanceof RelationRef
				? $source->getPath()
				: ($level instanceof RelationRef ? $level->getPath() : []);
			$loadKey = $this->levelLoadKey($level, $home['selection'], $fieldRef, $fieldName);
			$sqlKey = $this->ensureLevelFieldSelection($branch, $fieldRef, $placeKey, $loadKey, $path);
			$branch->bindPlaceToLoadKey($placeKey, $sqlKey);
			$aliases[] = $sqlKey;
		}

		return $aliases;
	}

	/**
	 * Emit one own-level / flat-under-this-destination column onto the branch query.
	 *
	 * @param list<string> $path
	 */
	public function ensureLevelFieldSelection(
		LoadBranch $branch,
		FieldRef $fieldRef,
		string $placeKey,
		string $loadKey,
		array $path,
	): string {
		$query = $branch->getQuery();
		$source = $this->resolveSelectionSource($branch, $fieldRef);
		$fieldName = $fieldRef->getField()->getName();
		$ownsSelect = $branch->getSource() === $query;

		// Authoring place alias already on this query (typical root): keep it and
		// add a load-local SQL column for the parser.
		if (
			$placeKey !== $loadKey
			&& $query->getSelections()->hasSelectionKey($placeKey)
		) {
			if (
				! $query->getSelections()->hasSelectionKey($loadKey)
				&& ! $query->getSelections()->hasNamedExpression($loadKey)
			) {
				$query->getSelections()->add(
					$source->field($fieldName)->as($loadKey),
					[SelectionTag::SQL_ONLY, SelectionTag::COLUMN],
				);
			}

			return $loadKey;
		}

		$alreadySelected = $query->getSelections()->hasSelectionKey($loadKey)
			|| $query->getSelections()->hasNamedExpression($loadKey)
			|| (
				$placeKey === $loadKey
				&& $query->getSelections()->hasSelectionKey($placeKey)
			);

		// Reuse an existing load key when it cannot collide with a parent column:
		// this branch owns the SELECT, the expression source is the query itself,
		// or the key is already namespaced (flats / l_* JOIN aliases).
		if (
			$alreadySelected
			&& (
				$ownsSelect
				|| $source === $query
				|| $loadKey !== $fieldName
			)
		) {
			return $loadKey;
		}

		if ($source === $query) {
			if ($loadKey === $fieldName) {
				$query->select($query->field($fieldName));
			} elseif (! $query->getSelections()->hasNamedExpression($loadKey)) {
				$query->select($query->field($fieldName)->as($loadKey));
			}

			return $loadKey;
		}

		// SEPARATE level query (branch owns the SELECT): preferred load keys are safe
		// (own fields and flats like author__name). JOIN onto a shared parent query
		// needs Cycle-like path-stable aliases to avoid collisions.
		if (
			$ownsSelect
			&& $loadKey !== ''
			&& ! $query->getSelections()->hasNamedExpression($loadKey)
		) {
			$query->select($source->field($fieldName)->as($loadKey));

			return $loadKey;
		}

		$alias = $loadKey !== '' && str_starts_with($loadKey, 'l_')
			? $loadKey
			: $this->runtime->stableJoinedAlias($path, $fieldName);

		if (! $query->getSelections()->hasNamedExpression($alias)) {
			$query->select($source->field($fieldName)->as($alias));
		}

		return $alias;
	}

	/**
	 * Resolve the query source that should provide a level column selection.
	 * Own-level fields use the branch source; flat related fields join under this level.
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
	 * Loaded to-one child destination for a relative relation path, or null when
	 * missing / many-valued (scalar flat cannot reuse a collection bag).
	 *
	 * @param list<string> $relativePath
	 */
	private function findLoadedToOneChildDestination(LoadBranch $parent, array $relativePath): ?RelationLoadBranch
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
	 * stable relative path key — place aliases are applied later by assemble.
	 */
	private function levelLoadKey(
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

	private function columnFieldRef(SelectionItem $selection): ?FieldRef
	{
		$expression = $selection->getExpression();

		if ($expression instanceof AliasedExpression) {
			$expression = $expression->getExpression();
		}

		return $expression instanceof FieldRef ? $expression : null;
	}
}
