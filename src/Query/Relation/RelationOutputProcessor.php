<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation;

use ON\Data\Query\Exception\RelationSelectionException;

/**
 * @phpstan-type ProjectedRow array{public: array<string, mixed>, identity: array<string, mixed>}
 * @phpstan-type HiddenPromotionItem array{identity: int, value: mixed, overlay: mixed}
 * @phpstan-type HiddenPromotion array{branch: RelationLoadBranch, collection: bool, value: mixed, overlay: mixed, items: array<int, HiddenPromotionItem>}
 * @phpstan-type HiddenPromotions array<string, HiddenPromotion>
 */
final class RelationOutputProcessor
{
	/**
	 * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
	 */
	public function processRoot(RootLoadBranch $root): array
	{
		$publicPaths = $this->publicScalarPathsFor($root);
		$projected = [];

		foreach ($root->getRecords() as $record) {
			$projected[] = $this->projectLevelRecord($root, $record, $publicPaths, projectScalars: true, promotionParent: 'root');
		}

		return $this->splitProjectedList($projected);
	}

	/**
	 * Remap SQL aliases to output names one row at a time (no identity fold).
	 * Used when there are no relation attaches — including flat JOIN selects
	 * that must keep multiplied rows.
	 *
	 * @param list<array<string, mixed>> $rows
	 * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
	 */
	public function processRawRows(RootLoadBranch $root, array $rows): array
	{
		$publicPaths = $this->publicScalarPathsFor($root);
		$projected = [];

		foreach ($rows as $row) {
			$projected[] = $this->projectLevelRecord(
				$root,
				new ResultRecord($root->extractRow($row, includeMissingAsNull: false)),
				$publicPaths,
				projectScalars: true,
				promotionParent: 'root',
			);
		}

		return $this->splitProjectedList($projected);
	}

	/**
	 * @param list<ProjectedRow> $projected
	 * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
	 */
	private function splitProjectedList(array $projected): array
	{
		$public = [];
		$identity = [];

		foreach ($projected as $row) {
			$public[] = $row['public'];
			$identity[] = $row['identity'];
		}

		return [$public, $identity];
	}

	/**
	 * @param ResultRecord|list<ResultRecord>|null $value
	 * @return list<ProjectedRow>|ProjectedRow|null
	 */
	private function buildVisibleOutput(RelationLoadBranch $branch, ResultRecord|array|null $value): array|null
	{
		if ($branch->returnsMany()) {
			$projected = [];

			foreach (is_array($value) ? $value : [] as $record) {
				$projected[] = $this->buildVisibleRecord($branch, $record);
			}

			return $projected;
		}

		if (! $value instanceof ResultRecord) {
			return null;
		}

		return $this->buildVisibleRecord($branch, $value);
	}

	/**
	 * @param list<ProjectedRow>|ProjectedRow|null $projected
	 * @return array{0: mixed, 1: mixed}
	 */
	private function splitVisibleOutput(array|null $projected, bool $many): array
	{
		if ($many) {
			$public = [];
			$identity = [];

			foreach (is_array($projected) ? $projected : [] as $row) {
				$public[] = $row['public'];
				$identity[] = $row['identity'];
			}

			return [$public, $identity];
		}

		if ($projected === null) {
			return [null, null];
		}

		/** @var ProjectedRow $projected */
		return [$projected['public'], $projected['identity']];
	}

	/**
	 * @param ResultRecord|list<ResultRecord>|null $value
	 * @return HiddenPromotions
	 */
	private function collectHiddenOutput(RelationLoadBranch $branch, ResultRecord|array|null $value): array
	{
		if ($branch->returnsMany()) {
			$promoted = $this->defaultHiddenPromotions($branch, true);

			foreach (is_array($value) ? $value : [] as $record) {
				$this->mergeHiddenCollectionPromotions(
					$promoted,
					$this->collectHiddenRecordOutput($branch, $record),
				);
			}

			return $promoted;
		}

		if (! $value instanceof ResultRecord) {
			return $this->defaultHiddenPromotions($branch);
		}

		return $this->collectHiddenRecordOutput($branch, $value);
	}

	/**
	 * @return ProjectedRow
	 */
	private function buildVisibleRecord(RelationLoadBranch $branch, ResultRecord $record): array
	{
		return $this->projectLevelRecord(
			$branch,
			$record,
			$this->publicScalarPathsFor($branch),
			projectScalars: $branch->getSelection()->isLoaded(),
			promotionParent: $this->promotionPath($branch),
		);
	}

	/**
	 * @param list<string> $publicPaths
	 * @return ProjectedRow
	 */
	private function projectLevelRecord(
		LoadBranch $branch,
		ResultRecord $record,
		array $publicPaths,
		bool $projectScalars,
		string $promotionParent,
	): array {
		$public = [];
		$identity = [];

		if ($projectScalars) {
			[$public, $identity] = $this->projectScalars($branch, $record, $publicPaths);
		}

		foreach ($branch->getChildren() as $child) {
			$name = $child->getRelationRef()->getName();
			$value = $record->children[$name] ?? ($child->returnsMany() ? [] : null);

			if ($child->getSelection()->isVisible()) {
				[$public[$name], $identity[$name]] = $this->splitVisibleOutput(
					$this->buildVisibleOutput($child, $value),
					$child->returnsMany(),
				);

				continue;
			}

			$this->mergePromotions($public, $identity, $this->collectHiddenOutput($child, $value), $promotionParent);
		}

		return ['public' => $public, 'identity' => $identity];
	}

	/**
	 * @param list<string> $publicPaths
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
	 */
	private function projectScalars(LoadBranch $branch, ResultRecord $record, array $publicPaths): array
	{
		$public = [];
		$identity = [];

		foreach ($publicPaths as $path) {
			$this->assignPathValue($public, $branch, $record, $path);
		}

		foreach ($this->implicitScalarPathsFor($branch) as $path) {
			$this->assignPathValue($identity, $branch, $record, $path);
		}

		foreach ($branch->getRepresentationSchema()->getSources() as $source) {
			foreach ($source->getPrimaryKeyPaths() as $path) {
				if ($path === null || array_key_exists($path, $identity) || ! array_key_exists($path, $public)) {
					continue;
				}

				$identity[$path] = $public[$path];
			}
		}

		return [$public, $identity];
	}

	/**
	 * @param array<string, mixed> $item
	 */
	private function assignPathValue(array &$item, LoadBranch $branch, ResultRecord $record, string $path): bool
	{
		$binding = $branch->getFetchMapping()->resolvePath($branch, $path);

		if ($binding === null) {
			if (! $branch->hasQuery() && array_key_exists($path, $record->values)) {
				$item[$path] = $record->values[$path];

				return true;
			}

			return false;
		}

		$valueKey = $binding['valueKey'];
		$childPath = $binding['childPath'];

		if ($childPath !== null) {
			$fromChild = $this->scalarFromChildPath($branch, $record, $childPath, $valueKey);

			if ($fromChild['found']) {
				$item[$path] = $fromChild['value'];

				return true;
			}

			return false;
		}

		if (array_key_exists($valueKey, $record->values)) {
			$item[$path] = $record->values[$valueKey];

			return true;
		}

		return false;
	}

	/**
	 * @param list<string> $relativePath
	 * @return array{found: bool, value: mixed}
	 */
	private function scalarFromChildPath(
		LoadBranch $branch,
		ResultRecord $record,
		array $relativePath,
		string $valueKey,
	): array {
		$currentRecord = $record;
		$currentBranch = $branch;

		foreach ($relativePath as $segment) {
			$child = $this->childBranchNamed($currentBranch, $segment);

			if (! $child instanceof RelationLoadBranch || $child->returnsMany()) {
				return ['found' => false, 'value' => null];
			}

			$next = $currentRecord->children[$segment] ?? null;

			if ($next === null) {
				return ['found' => true, 'value' => null];
			}

			if (! $next instanceof ResultRecord) {
				return ['found' => false, 'value' => null];
			}

			$currentRecord = $next;
			$currentBranch = $child;
		}

		if (array_key_exists($valueKey, $currentRecord->values)) {
			return ['found' => true, 'value' => $currentRecord->values[$valueKey]];
		}

		return ['found' => false, 'value' => null];
	}

	private function childBranchNamed(LoadBranch $parent, string $name): ?RelationLoadBranch
	{
		foreach ($parent->getChildren() as $child) {
			if ($child->getRelationRef()->getName() === $name) {
				return $child;
			}
		}

		return null;
	}

	/**
	 * @return list<string>
	 */
	private function publicScalarPathsFor(LoadBranch $branch): array
	{
		return $branch->getRepresentationSchema()->getPublicScalarPaths();
	}

	/**
	 * @return list<string>
	 */
	private function implicitScalarPathsFor(LoadBranch $branch): array
	{
		return $branch->getRepresentationSchema()->getImplicitScalarPaths();
	}

	/**
	 * @return HiddenPromotions
	 */
	private function collectHiddenRecordOutput(RelationLoadBranch $branch, ResultRecord $record): array
	{
		$promoted = [];

		foreach ($branch->getChildren() as $child) {
			$name = $child->getRelationRef()->getName();
			$value = $record->children[$name] ?? ($child->returnsMany() ? [] : null);

			if ($child->getSelection()->isVisible()) {
				$items = $this->projectPromotionItems($child, $value);
				$first = $items !== [] ? reset($items) : null;
				$promoted[$name] = [
					'branch' => $child,
					'collection' => $child->returnsMany(),
					'value' => $child->returnsMany()
						? array_column($items, 'value')
						: ($first['value'] ?? null),
					'overlay' => $child->returnsMany()
						? array_column($items, 'overlay')
						: ($first['overlay'] ?? null),
					'items' => $items,
				];

				continue;
			}

			$this->mergeHiddenNameMaps(
				$promoted,
				$this->collectHiddenOutput($child, $value),
				$this->promotionPath($branch),
			);
		}

		return $promoted;
	}

	/**
	 * @return HiddenPromotions
	 */
	private function defaultHiddenPromotions(RelationLoadBranch $branch, bool $forceCollection = false): array
	{
		$promoted = [];

		foreach ($branch->getChildren() as $child) {
			$name = $child->getRelationRef()->getName();

			if ($child->getSelection()->isVisible()) {
				$collection = $forceCollection || $child->returnsMany();
				$promoted[$name] = [
					'branch' => $child,
					'collection' => $collection,
					'value' => $collection ? [] : null,
					'overlay' => $collection ? [] : null,
					'items' => [],
				];

				continue;
			}

			foreach ($this->defaultHiddenPromotions($child, $forceCollection || $child->returnsMany()) as $childName => $entry) {
				if (isset($promoted[$childName]) && $promoted[$childName]['branch'] !== $entry['branch']) {
					throw RelationSelectionException::ambiguousPromotion($this->promotionPath($branch), $childName);
				}

				$promoted[$childName] = $entry;
			}
		}

		return $promoted;
	}

	/**
	 * @param ResultRecord|list<ResultRecord>|null $value
	 * @return array<int, HiddenPromotionItem>
	 */
	private function projectPromotionItems(RelationLoadBranch $branch, ResultRecord|array|null $value): array
	{
		if ($branch->returnsMany()) {
			$items = [];

			foreach (is_array($value) ? $value : [] as $record) {
				$identity = spl_object_id($record);

				if (isset($items[$identity])) {
					continue;
				}

				$projected = $this->buildVisibleRecord($branch, $record);
				$items[$identity] = [
					'identity' => $identity,
					'value' => $projected['public'],
					'overlay' => $projected['identity'],
				];
			}

			return $items;
		}

		if (! $value instanceof ResultRecord) {
			return [];
		}

		$identity = spl_object_id($value);
		$projected = $this->buildVisibleRecord($branch, $value);

		return [
			$identity => [
				'identity' => $identity,
				'value' => $projected['public'],
				'overlay' => $projected['identity'],
			],
		];
	}

	/**
	 * @param array<string, mixed> $public
	 * @param array<string, mixed> $identity
	 * @param HiddenPromotions $promotions
	 */
	private function mergePromotions(array &$public, array &$identity, array $promotions, string $parentPath): void
	{
		foreach ($promotions as $name => $entry) {
			if (array_key_exists($name, $public) || array_key_exists($name, $identity)) {
				throw RelationSelectionException::ambiguousPromotion($parentPath, $name);
			}

			$public[$name] = $entry['value'];
			$identity[$name] = $entry['overlay'];
		}
	}

	/**
	 * @param HiddenPromotions $target
	 * @param HiddenPromotions $incoming
	 */
	private function mergeHiddenNameMaps(array &$target, array $incoming, string $parentPath): void
	{
		foreach ($incoming as $name => $entry) {
			if (isset($target[$name]) && $target[$name]['branch'] !== $entry['branch']) {
				throw RelationSelectionException::ambiguousPromotion($parentPath, $name);
			}

			$target[$name] = $entry;
		}
	}

	/**
	 * @param HiddenPromotions $target
	 * @param HiddenPromotions $incoming
	 */
	private function mergeHiddenCollectionPromotions(array &$target, array $incoming): void
	{
		foreach ($incoming as $name => $entry) {
			$branch = $entry['branch'];

			if (! isset($target[$name])) {
				$target[$name] = [
					'branch' => $branch,
					'collection' => true,
					'value' => [],
					'overlay' => [],
					'items' => [],
				];
			} elseif ($target[$name]['branch'] !== $branch) {
				throw RelationSelectionException::ambiguousPromotion($this->promotionPath($branch), $name);
			}

			foreach ($entry['items'] as $recordId => $item) {
				if (! isset($target[$name]['items'][$recordId])) {
					$target[$name]['items'][$recordId] = $item;
					$target[$name]['value'][] = $item['value'];
					$target[$name]['overlay'][] = $item['overlay'];
				}
			}
		}
	}

	private function promotionPath(RelationLoadBranch $branch): string
	{
		return implode('.', $branch->getRelationRef()->getPath());
	}
}
