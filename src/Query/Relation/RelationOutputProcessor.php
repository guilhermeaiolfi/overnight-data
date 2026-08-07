<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation;

use ON\Data\ORM\Representation\Schema\RepresentationSchema;
use ON\Data\Query\Exception\RelationSelectionException;
use ON\Data\Query\Selection\SelectionTag;

/**
 * @phpstan-type HiddenPromotionItem array{identity: string, value: mixed}
 * @phpstan-type HiddenPromotion array{branch: RelationLoadBranch, collection: bool, value: mixed, items: list<HiddenPromotionItem>}
 * @phpstan-type HiddenPromotions array<string, HiddenPromotion>
 */
final class RelationOutputProcessor
{
	public function __construct(
		private readonly ?RepresentationSchema $schema = null,
	) {
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function processRoot(RootLoadBranch $root): array
	{
		$placeKeys = $this->placeKeysFor($root);
		$records = [];

		foreach ($root->getRootNode()->getResult() as $record) {
			$records[] = $this->projectLevelRecord($root, $record, $placeKeys, projectScalars: true, promotionParent: 'root');
		}

		return $records;
	}

	private function buildVisibleOutput(RelationLoadBranch $branch, mixed $value): mixed
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
	 * @return array<string, mixed>
	 */
	private function buildVisibleRecord(RelationLoadBranch $branch, array $record): array
	{
		return $this->projectLevelRecord(
			$branch,
			$record,
			$this->placeKeysFor($branch),
			projectScalars: $branch->getSelection()->isLoaded(),
			promotionParent: $this->promotionPath($branch),
		);
	}

	/**
	 * @param list<string> $placeKeys
	 * @param array<string, mixed> $record
	 * @return array<string, mixed>
	 */
	private function projectLevelRecord(
		LoadBranch $branch,
		array $record,
		array $placeKeys,
		bool $projectScalars,
		string $promotionParent,
	): array {
		$item = $projectScalars
			? $this->projectScalars($branch, $record, $placeKeys)
			: [];

		foreach ($branch->getChildren() as $child) {
			$name = $child->getRelationRef()->getName();
			$value = $record[$name] ?? ($child->returnsMany() ? [] : null);

			if ($child->getSelection()->isVisible()) {
				$item[$name] = $this->buildVisibleOutput($child, $value);

				continue;
			}

			$this->mergePromotions($item, $this->collectHiddenOutput($child, $value), $promotionParent);
		}

		return $item;
	}

	/**
	 * @param list<string> $placeKeys
	 * @param array<string, mixed> $record
	 * @return array<string, mixed>
	 */
	private function projectScalars(LoadBranch $branch, array $record, array $placeKeys): array
	{
		$item = [];

		foreach ($placeKeys as $placeKey) {
			$loadKey = $branch->loadKeyForPlace($placeKey);

			if (array_key_exists($loadKey, $record)) {
				$item[$placeKey] = $record[$loadKey];
			}
		}

		// Keep INTERNAL identity keys for writable track(); SelectQuery::publicRow strips them.
		foreach ($branch->getSelections()->getByTag(SelectionTag::INTERNAL) as $selection) {
			$placeKey = $selection->getSelectionKey();
			$loadKey = $branch->loadKeyForPlace($placeKey);

			if (array_key_exists($loadKey, $record)) {
				$item[$placeKey] = $record[$loadKey];
			}
		}

		return $item;
	}

	/**
	 * Public own-level keys stay on PUBLIC selections; schema only adds COLUMN-only
	 * flats (non-empty sourcePath). Full schema fields include PK backfill for
	 * adoption and must not drive public place.
	 *
	 * @return list<string>
	 */
	private function placeKeysFor(LoadBranch $branch): array
	{
		$keys = [];

		foreach ($branch->getSelections()->getByTag(SelectionTag::PUBLIC) as $selection) {
			$keys[] = $selection->getSelectionKey();
		}

		$levelSchema = $this->schemaForLevel($branch);

		if ($levelSchema instanceof RepresentationSchema) {
			$keys = $this->mergeFlatSchemaPaths($keys, $levelSchema);
		}

		return $keys;
	}

	/**
	 * @param list<string> $keys
	 * @return list<string>
	 */
	private function mergeFlatSchemaPaths(array $keys, RepresentationSchema $schema): array
	{
		foreach ($schema->getFields() as $field) {
			if ($field->getSourcePath() === []) {
				continue;
			}

			$path = $field->getPath();

			if (! in_array($path, $keys, true)) {
				$keys[] = $path;
			}
		}

		return $keys;
	}

	private function schemaForLevel(LoadBranch $branch): ?RepresentationSchema
	{
		if (! $this->schema instanceof RepresentationSchema) {
			return null;
		}

		if ($branch instanceof RootLoadBranch) {
			return $this->schema;
		}

		if (! $branch instanceof RelationLoadBranch) {
			return null;
		}

		$current = $this->schema;

		foreach ($branch->getRelationRef()->getPath() as $segment) {
			if (! $current->hasRelation($segment)) {
				return null;
			}

			$current = $current->getRelation($segment)->getRelatedSchema();
		}

		return $current;
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

				$items[] = [
					'identity' => $this->recordIdentity($branch, $record),
					'value' => $this->buildVisibleRecord($branch, $record),
				];
			}

			return $items;
		}

		if (! is_array($value)) {
			return [];
		}

		return [[
			'identity' => $this->recordIdentity($branch, $value),
			'value' => $this->buildVisibleRecord($branch, $value),
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
	 * @param array<string, mixed> $item
	 * @param HiddenPromotions $promotions
	 */
	private function mergePromotions(array &$item, array $promotions, string $parentPath): void
	{
		foreach ($promotions as $name => $entry) {
			if (array_key_exists($name, $item)) {
				throw RelationSelectionException::ambiguousPromotion($parentPath, $name);
			}

			$item[$name] = $entry['value'];
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
					'items' => [],
				];
			} elseif ($target[$name]['branch'] !== $branch) {
				throw RelationSelectionException::ambiguousPromotion($this->promotionPath($branch), $name);
			}

			foreach ($entry['items'] as $item) {
				if (! $this->containsPromotionItem($target[$name]['items'], $item['identity'])) {
					$target[$name]['items'][] = $item;
					$target[$name]['value'][] = $item['value'];
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
