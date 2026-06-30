<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Query\Relation\Loader\LoaderInterface;
use ON\Data\Query\SelectQuery;

final class RelationLoadBranch extends LoadBranch
{
	/**
	 * @var array<string, true>
	 */
	private array $parserFieldMap = [];

	/**
	 * @var array<string, true>
	 */
	private array $publicFieldMap = [];

	/**
	 * @var list<string>
	 */
	private array $parserFields = [];

	/**
	 * @var list<string>
	 */
	private array $publicFieldOrder = [];

	private ?string $continuationMethod = null;

	private ?SelectQuery $continuationQuery = null;

	private ?bool $joinedAttachment = null;

	/**
	 * @param list<string> $publicFields
	 */
	public function __construct(
		private readonly RelationSelection $selection,
		private readonly LoadBranch $parent,
		private readonly LoaderInterface $loader,
		array $publicFields,
	) {
		$this->parent->addChild($this);
		$this->addPublicFields($publicFields);
	}

	public function getSelection(): RelationSelection
	{
		return $this->selection;
	}

	public function getRelationRef(): RelationRef
	{
		return $this->selection->getRelationRef();
	}

	public function getParent(): LoadBranch
	{
		return $this->parent;
	}

	public function getLoader(): LoaderInterface
	{
		return $this->loader;
	}

	public function getCollection(): CollectionInterface
	{
		return $this->getRelationRef()->getCollection();
	}

	/**
	 * @param list<string> $fieldNames
	 * @return list<string>
	 */
	public function requireFields(array $fieldNames): array
	{
		$added = [];

		foreach ($fieldNames as $fieldName) {
			if (isset($this->parserFieldMap[$fieldName])) {
				$added[] = $fieldName;

				continue;
			}

			$this->parserFieldMap[$fieldName] = true;
			$this->parserFields[] = $fieldName;
			$added[] = $fieldName;
		}

		return $added;
	}

	/**
	 * @param list<string> $fieldNames
	 * @return list<string>
	 */
	public function addPublicFields(array $fieldNames): array
	{
		$added = [];

		foreach ($fieldNames as $fieldName) {
			if (! isset($this->publicFieldMap[$fieldName])) {
				$this->publicFieldMap[$fieldName] = true;
				$this->publicFieldOrder[] = $fieldName;
			}

			$added[] = $fieldName;
		}

		$this->requireFields($fieldNames);

		return $added;
	}

	/**
	 * @return list<string>
	 */
	public function getParserFields(): array
	{
		return $this->parserFields;
	}

	/**
	 * @return list<string>
	 */
	public function getPublicFields(): array
	{
		return $this->publicFieldOrder;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function getReferenceValues(): array
	{
		return $this->getNode()->getReferenceValues();
	}

	public function setContinuation(string $method, SelectQuery $query): void
	{
		$this->continuationMethod = $method;
		$this->continuationQuery = $query;
	}

	public function clearContinuation(): void
	{
		$this->continuationMethod = null;
		$this->continuationQuery = null;
	}

	public function getContinuationMethod(): ?string
	{
		return $this->continuationMethod;
	}

	public function getContinuationQuery(): ?SelectQuery
	{
		return $this->continuationQuery;
	}

	public function setJoinedAttachment(bool $joined): void
	{
		$this->joinedAttachment = $joined;
	}

	public function isJoinedAttachment(): bool
	{
		return $this->joinedAttachment ?? throw new \LogicException('Load branch attachment mode is not configured.');
	}

	public function returnsMany(): bool
	{
		return $this->getRelationRef()->getDefinition()->getCardinality() === 'many';
	}

	public function buildVisibleOutput(mixed $value): mixed
	{
		if ($this->returnsMany()) {
			$projected = [];

			foreach (is_array($value) ? $value : [] as $item) {
				$record = $this->payloadRecord(is_array($item) ? $item : []);

				if ($record === null) {
					continue;
				}

				$projected[] = $this->buildVisibleRecord($record);
			}

			return $projected;
		}

		if ($value === null) {
			return null;
		}

		$record = $this->payloadRecord(is_array($value) ? $value : []);

		return $record === null
			? null
			: $this->buildVisibleRecord($record);
	}

	/**
	 * @return array<string, array{branch: self, collection: bool, value: mixed, items: list<array{identity: string, value: mixed}>}>
	 */
	public function collectHiddenOutput(mixed $value): array
	{
		if ($this->returnsMany()) {
			$promoted = $this->defaultHiddenPromotions(true);

			foreach (is_array($value) ? $value : [] as $item) {
				$this->mergeHiddenCollectionPromotions(
					$promoted,
					$this->collectHiddenRecordOutput(is_array($item) ? $item : []),
				);
			}

			return $promoted;
		}

		if ($value === null) {
			return $this->defaultHiddenPromotions();
		}

		return $this->collectHiddenRecordOutput(is_array($value) ? $value : []);
	}

	/**
	 * @param array<string, mixed> $record
	 * @return array<string, mixed>
	 */
	private function buildVisibleRecord(array $record): array
	{
		$item = [];

		if ($this->selection->isLoaded()) {
			foreach ($this->publicFieldOrder as $fieldName) {
				if (array_key_exists($fieldName, $record)) {
					$item[$fieldName] = $record[$fieldName];
				}
			}
		}

		foreach ($this->getChildren() as $child) {
			$name = $child->getRelationRef()->getName();
			$value = $record[$name] ?? ($child->returnsMany() ? [] : null);

			if ($child->getSelection()->isVisible()) {
				$item[$name] = $child->buildVisibleOutput($value);

				continue;
			}

			$this->mergePromotions(
				$item,
				$child->collectHiddenOutput($value),
				$this->promotionPath(),
			);
		}

		return $item;
	}

	/**
	 * @param array<string, mixed> $record
	 * @return array<string, array{branch: self, collection: bool, value: mixed, items: list<array{identity: string, value: mixed}>}>
	 */
	private function collectHiddenRecordOutput(array $record): array
	{
		$promoted = [];
		$payload = $this->payloadRecord($record) ?? [];

		foreach ($this->getChildren() as $child) {
			$name = $child->getRelationRef()->getName();
			$value = $payload[$name] ?? ($child->returnsMany() ? [] : null);

			if ($child->getSelection()->isVisible()) {
				$items = $child->projectPromotionItems($value);
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

			$this->mergeHiddenNameMaps($promoted, $child->collectHiddenOutput($value), $this->promotionPath());
		}

		return $promoted;
	}

	/**
	 * @return array<string, array{branch: self, collection: bool, value: mixed, items: list<array{identity: string, value: mixed}>}>
	 */
	private function defaultHiddenPromotions(bool $forceCollection = false): array
	{
		$promoted = [];

		foreach ($this->getChildren() as $child) {
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

			foreach ($child->defaultHiddenPromotions($forceCollection || $child->returnsMany()) as $childName => $entry) {
				if (isset($promoted[$childName]) && $promoted[$childName]['branch'] !== $entry['branch']) {
					throw \ON\Data\Query\Exception\RelationSelectionException::ambiguousPromotion($this->promotionPath(), $childName);
				}

				$promoted[$childName] = $entry;
			}
		}

		return $promoted;
	}

	/**
	 * @return list<array{identity: string, value: mixed}>
	 */
	private function projectPromotionItems(mixed $value): array
	{
		if ($this->returnsMany()) {
			$items = [];

			foreach (is_array($value) ? $value : [] as $record) {
				if (! is_array($record)) {
					continue;
				}

				$items[] = [
					'identity' => $this->recordIdentity($record),
					'value' => $this->buildVisibleRecord($record),
				];
			}

			return $items;
		}

		if (! is_array($value)) {
			return [];
		}

		return [[
			'identity' => $this->recordIdentity($value),
			'value' => $this->buildVisibleRecord($value),
		]];
	}

	private function recordIdentity(array $value): string
	{
		$identity = [];

		foreach ($this->getRelationRef()->getCollection()->getPrimaryKey() as $fieldName) {
			$identity[$fieldName] = $value[$fieldName] ?? null;
		}

		return json_encode($identity, JSON_THROW_ON_ERROR);
	}

	private function promotionPath(): string
	{
		return implode('.', $this->getRelationRef()->getPath());
	}
}
