<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation;

use ON\Data\Query\Exception\RelationSelectionException;
use ON\Data\Query\Expression\AliasedExpression;
use ON\Data\Query\Selection\SelectionItem;

final class RelationOutputProcessor
{
	/**
	 * @return list<array<string, mixed>>
	 */
	public function processRoot(RootLoadBranch $root): array
	{
		$records = [];
		$publicColumns = array_fill_keys($this->rootSelectionKeys($root->getSelections()->getPublicItems()), true);

		foreach ($root->getRootNode()->getResult() as $record) {
			$records[] = $this->processRootRecord($root, $record, $publicColumns);
		}

		return $records;
	}

	/**
	 * @param array<string, bool> $publicColumns
	 * @param array<string, mixed> $record
	 * @return array<string, mixed>
	 */
	private function processRootRecord(RootLoadBranch $root, array $record, array $publicColumns): array
	{
		$item = [];

		foreach ($record as $key => $value) {
			if (isset($publicColumns[$key])) {
				$item[$key] = $value;
			}
		}

		foreach ($root->getChildren() as $child) {
			$name = $child->getRelationRef()->getName();
			$value = $record[$name] ?? ($child->returnsMany() ? [] : null);

			if ($child->getSelection()->isVisible()) {
				$item[$name] = $this->buildVisibleOutput($child, $value);

				continue;
			}

			$this->mergePromotions($item, $this->collectHiddenOutput($child, $value), 'root');
		}

		return $item;
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
	 * @return array<string, array{branch: RelationLoadBranch, collection: bool, value: mixed, items: list<array{identity: string, value: mixed}>}>
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
		$item = [];

		if ($branch->getSelection()->isLoaded()) {
			foreach ($branch->getSelections()->getPublicItems() as $selection) {
				$fieldName = $this->relationSelectionFieldName($selection);

				if (array_key_exists($fieldName, $record)) {
					$item[$fieldName] = $record[$fieldName];
				}
			}
		}

		foreach ($branch->getChildren() as $child) {
			$name = $child->getRelationRef()->getName();
			$value = $record[$name] ?? ($child->returnsMany() ? [] : null);

			if ($child->getSelection()->isVisible()) {
				$item[$name] = $this->buildVisibleOutput($child, $value);

				continue;
			}

			$this->mergePromotions(
				$item,
				$this->collectHiddenOutput($child, $value),
				$this->promotionPath($branch),
			);
		}

		return $item;
	}

	/**
	 * @param array<string, mixed> $record
	 * @return array<string, array{branch: RelationLoadBranch, collection: bool, value: mixed, items: list<array{identity: string, value: mixed}>}>
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
	 * @return array<string, array{branch: RelationLoadBranch, collection: bool, value: mixed, items: list<array{identity: string, value: mixed}>}>
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
	 * @return list<array{identity: string, value: mixed}>
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
	 * @param array<string, array{branch: RelationLoadBranch, collection: bool, value: mixed, items: list<array{identity: string, value: mixed}>}> $promotions
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
	 * @param array<string, array{branch: RelationLoadBranch, collection: bool, value: mixed, items: list<array{identity: string, value: mixed}>}> $target
	 * @param array<string, array{branch: RelationLoadBranch, collection: bool, value: mixed, items: list<array{identity: string, value: mixed}>}> $incoming
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
	 * @param array<string, array{branch: RelationLoadBranch, collection: bool, value: mixed, items: list<array{identity: string, value: mixed}>}> $target
	 * @param array<string, array{branch: RelationLoadBranch, collection: bool, value: mixed, items: list<array{identity: string, value: mixed}>}> $incoming
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
	 * @param list<array{identity: string, value: mixed}> $existing
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

	private function relationSelectionFieldName(SelectionItem $selection): string
	{
		return $selection->getExpression()->getField()->getName();
	}

	/**
	 * @param list<SelectionItem> $selections
	 * @return list<string>
	 */
	private function rootSelectionKeys(array $selections): array
	{
		return array_map($this->rootSelectionKey(...), $selections);
	}

	private function rootSelectionKey(SelectionItem $selection): string
	{
		$expression = $selection->getExpression();

		return $expression instanceof AliasedExpression
			? $expression->getAlias()
			: implode('.', $expression->getPath());
	}
}
