<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation;

use ON\Data\Query\Exception\LoadRuntimeException;
use ON\Data\Query\Exception\RelationSelectionException;
use ON\Data\Schema\RepresentationSchema;

/**
 * @phpstan-type ProjectedRow array{public: array<string, mixed>, identity: array<string, mixed>}
 * @phpstan-type HiddenPromotionItem array{identity: string, value: mixed, overlay: mixed}
 * @phpstan-type HiddenPromotion array{branch: RelationLoadBranch, collection: bool, value: mixed, overlay: mixed, items: list<HiddenPromotionItem>}
 * @phpstan-type HiddenPromotions array<string, HiddenPromotion>
 */
final class RelationOutputProcessor
{
	public function __construct(
		private readonly ?RepresentationSchema $schema = null,
	) {
	}

	/**
	 * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
	 */
	public function processRoot(RootLoadBranch $root): array
	{
		if (! $this->schema instanceof RepresentationSchema) {
			throw LoadRuntimeException::schemaMissing();
		}

		$publicPaths = $this->publicScalarPathsFor($root);
		$projected = [];

		foreach ($root->getRootNode()->getResult() as $record) {
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
		if (! $this->schema instanceof RepresentationSchema) {
			throw LoadRuntimeException::schemaMissing();
		}

		$publicPaths = $this->publicScalarPathsFor($root);
		$projected = [];

		foreach ($rows as $row) {
			$projected[] = $this->projectLevelRecord(
				$root,
				$this->sqlRowToBag($root, $row),
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
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function sqlRowToBag(LoadBranch $branch, array $row): array
	{
		$bag = [];

		foreach ($branch->getRemapColumns() as $column => $sql) {
			if (array_key_exists($sql, $row)) {
				$bag[$column] = $row[$sql];
			}
		}

		return $bag;
	}

	/**
	 * @return list<ProjectedRow>|ProjectedRow|null
	 */
	private function buildVisibleOutput(RelationLoadBranch $branch, mixed $value): array|null
	{
		if ($branch->returnsMany()) {
			$projected = [];

			foreach (is_array($value) ? $value : [] as $item) {
				$record = $this->payloadRecord($branch, is_array($item) ? $item : []);

				if ($record === null) {
					continue;
				}

				$projected[] = $this->buildVisibleRecord($branch, $record);
			}

			return $projected;
		}

		if ($value === null) {
			return null;
		}

		$record = $this->payloadRecord($branch, is_array($value) ? $value : []);

		return $record === null
			? null
			: $this->buildVisibleRecord($branch, $record);
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
	 * @return HiddenPromotions
	 */
	private function collectHiddenOutput(RelationLoadBranch $branch, mixed $value): array
	{
		if ($branch->returnsMany()) {
			$promoted = $this->defaultHiddenPromotions($branch, true);

			foreach (is_array($value) ? $value : [] as $item) {
				$this->mergeHiddenCollectionPromotions(
					$promoted,
					$this->collectHiddenRecordOutput($branch, is_array($item) ? $item : []),
				);
			}

			return $promoted;
		}

		if ($value === null) {
			return $this->defaultHiddenPromotions($branch);
		}

		return $this->collectHiddenRecordOutput($branch, is_array($value) ? $value : []);
	}

	/**
	 * @param array<string, mixed> $record
	 * @return ProjectedRow
	 */
	private function buildVisibleRecord(RelationLoadBranch $branch, array $record): array
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
	 * @param array<string, mixed> $record
	 * @return ProjectedRow
	 */
	private function projectLevelRecord(
		LoadBranch $branch,
		array $record,
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
			$value = $record[$name] ?? ($child->returnsMany() ? [] : null);

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
	 * @param array<string, mixed> $record
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
	 */
	private function projectScalars(LoadBranch $branch, array $record, array $publicPaths): array
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
	 * @param array<string, mixed> $record
	 */
	private function assignPathValue(array &$item, LoadBranch $branch, array $record, string $path): bool
	{
		$loadKey = $branch->bagKeyForPath($path);
		$childPath = $branch->childPathForPath($path);

		if ($childPath !== null) {
			$fromChild = $this->scalarFromChildPath($branch, $record, $childPath, $loadKey);

			if ($fromChild['found']) {
				$item[$path] = $fromChild['value'];

				return true;
			}

			return false;
		}

		if (array_key_exists($loadKey, $record)) {
			$item[$path] = $record[$loadKey];

			return true;
		}

		return false;
	}

	/**
	 * @param array<string, mixed> $record
	 * @param list<string> $relativePath
	 * @return array{found: bool, value: mixed}
	 */
	private function scalarFromChildPath(
		LoadBranch $branch,
		array $record,
		array $relativePath,
		string $loadKey,
	): array {
		$currentRecord = $record;
		$currentBranch = $branch;

		foreach ($relativePath as $segment) {
			$child = $this->childBranchNamed($currentBranch, $segment);

			if (! $child instanceof RelationLoadBranch) {
				return ['found' => false, 'value' => null];
			}

			$raw = $currentRecord[$segment] ?? null;

			if ($raw === null) {
				return ['found' => true, 'value' => null];
			}

			if (! is_array($raw) || $child->returnsMany()) {
				return ['found' => false, 'value' => null];
			}

			$payload = $this->payloadRecord($child, $raw);

			if ($payload === null) {
				return ['found' => true, 'value' => null];
			}

			$currentRecord = $payload;
			$currentBranch = $child;
		}

		if (array_key_exists($loadKey, $currentRecord)) {
			return ['found' => true, 'value' => $currentRecord[$loadKey]];
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
	 * Ordered public scalar paths from this branch's schema node
	 * ({@see RepresentationSchema::getPublicScalarPaths()}). Implicit PK backfill is omitted.
	 * Query EXPLICIT tags are not a path fallback.
	 *
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
	 * @param array<string, mixed> $record
	 * @return HiddenPromotions
	 */
	private function collectHiddenRecordOutput(RelationLoadBranch $branch, array $record): array
	{
		$promoted = [];
		$payload = $this->payloadRecord($branch, $record) ?? [];

		foreach ($branch->getChildren() as $child) {
			$name = $child->getRelationRef()->getName();
			$value = $payload[$name] ?? ($child->returnsMany() ? [] : null);

			if ($child->getSelection()->isVisible()) {
				$items = $this->projectPromotionItems($child, $value);
				$promoted[$name] = [
					'branch' => $child,
					'collection' => $child->returnsMany(),
					'value' => $child->returnsMany()
						? array_column($items, 'value')
						: ($items[0]['value'] ?? null),
					'overlay' => $child->returnsMany()
						? array_column($items, 'overlay')
						: ($items[0]['overlay'] ?? null),
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
	 * @return list<HiddenPromotionItem>
	 */
	private function projectPromotionItems(RelationLoadBranch $branch, mixed $value): array
	{
		if ($branch->returnsMany()) {
			$items = [];

			foreach (is_array($value) ? $value : [] as $record) {
				if (! is_array($record)) {
					continue;
				}

				$projected = $this->buildVisibleRecord($branch, $record);
				$items[] = [
					'identity' => $this->recordIdentity($branch, $record),
					'value' => $projected['public'],
					'overlay' => $projected['identity'],
				];
			}

			return $items;
		}

		if (! is_array($value)) {
			return [];
		}

		$projected = $this->buildVisibleRecord($branch, $value);

		return [[
			'identity' => $this->recordIdentity($branch, $value),
			'value' => $projected['public'],
			'overlay' => $projected['identity'],
		]];
	}

	/**
	 * @param array<string, mixed> $record
	 * @return array<string, mixed>|null
	 */
	private function payloadRecord(LoadBranch $branch, array $record): ?array
	{
		$container = $branch->getPublicPayloadChild();

		if ($container === null) {
			return $record;
		}

		$payload = $record[$container] ?? null;

		return is_array($payload) ? $payload : null;
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

			foreach ($entry['items'] as $item) {
				if (! $this->containsPromotionItem($target[$name]['items'], $item['identity'])) {
					$target[$name]['items'][] = $item;
					$target[$name]['value'][] = $item['value'];
					$target[$name]['overlay'][] = $item['overlay'];
				}
			}
		}
	}

	/**
	 * @param list<HiddenPromotionItem> $existing
	 */
	private function containsPromotionItem(array $existing, string $candidateIdentity): bool
	{
		foreach ($existing as $item) {
			if ($item['identity'] === $candidateIdentity) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string, mixed> $value
	 */
	private function recordIdentity(RelationLoadBranch $branch, array $value): string
	{
		$identity = [];

		foreach ($branch->getRelationRef()->getCollection()->getPrimaryKey() as $fieldName) {
			$identity[$fieldName] = $value[$fieldName] ?? null;
		}

		return json_encode($identity, JSON_THROW_ON_ERROR);
	}

	private function promotionPath(RelationLoadBranch $branch): string
	{
		return implode('.', $branch->getRelationRef()->getPath());
	}
}
