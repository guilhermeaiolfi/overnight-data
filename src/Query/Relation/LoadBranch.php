<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation;

use Closure;
use LogicException;
use ON\Data\Query\Exception\RelationSelectionException;
use ON\Data\Query\QuerySourceInterface;
use ON\Data\Query\Relation\Loader\LoaderInterface;
use ON\Data\Query\Result\Parser\AbstractNode;
use ON\Data\Query\Result\Parser\RootNode;
use ON\Data\Query\SelectQuery;

final class LoadBranch
{
	private ?AbstractNode $node = null;

	private ?SelectQuery $query = null;

	private ?QuerySourceInterface $source = null;

	private ?RelationRef $queryLocalRelation = null;

	/**
	 * @var array<string, true>
	 */
	private array $fieldMap = [];

	/**
	 * @var array<string, true>
	 */
	private array $publicFieldMap = [];

	/**
	 * @var list<string>
	 */
	private array $fieldOrder = [];

	/**
	 * @var list<string>
	 */
	private array $publicFieldOrder = [];

	private ?string $scheduledMethod = null;

	private ?SelectQuery $scheduledBoundaryQuery = null;

	private ?bool $joinedAttachment = null;

	private ?AbstractNode $publicNode = null;

	private ?string $publicPayloadChild = null;

	/**
	 * @var list<self>
	 */
	private array $children = [];

	private readonly ?RelationSelection $selection;

	private readonly ?self $parent;

	private readonly ?LoaderInterface $loader;

	/**
	 * @var list<string>
	 */
	private array $rootColumns = [];

	/**
	 * @var list<string>
	 */
	private array $rootValueAliases = [];

	/**
	 * @var array<string, true>
	 */
	private array $rootPublicColumns = [];

	/**
	 * @var array<string, string>
	 */
	private array $rootFieldParserNames = [];

	private ?Closure $rootFieldResolver = null;

	/**
	 * @param list<string> $publicFields
	 */
	private function __construct(
		?RelationSelection $selection,
		?self $parent,
		?LoaderInterface $loader,
		array $publicFields,
	) {
		$this->selection = $selection;
		$this->parent = $parent;
		$this->loader = $loader;
		$this->addPublicFields($publicFields);
	}

	public static function root(SelectQuery $query): self
	{
		$branch = new self(null, null, null, []);
		$branch->setQueryContext($query, $query, null);

		return $branch;
	}

	/**
	 * @param list<string> $publicFields
	 */
	public static function relation(
		RelationSelection $selection,
		self $parent,
		LoaderInterface $loader,
		array $publicFields,
	): self {
		$branch = new self($selection, $parent, $loader, $publicFields);
		$parent->addChild($branch);

		return $branch;
	}

	public function isRoot(): bool
	{
		return $this->selection === null;
	}

	public function getSelection(): RelationSelection
	{
		return $this->selection ?? throw new LogicException('Root load branch does not have a relation selection.');
	}

	public function getRelation(): RelationRef
	{
		return $this->getSelection()->getRelation();
	}

	public function getParent(): ?self
	{
		return $this->parent;
	}

	public function getLoader(): LoaderInterface
	{
		return $this->loader ?? throw new LogicException('Root load branch does not have a relation loader.');
	}

	public function requireFields(array $fieldNames): array
	{
		if ($fieldNames === []) {
			return [];
		}

		if ($this->isRoot()) {
			$resolver = $this->rootFieldResolver ?? throw new LogicException('Root load branch field resolver is not configured.');
			$collection = $this->getQuery()->getCollection();
			$normalized = array_map(
				static fn (string $fieldName): string => $collection->getField($fieldName)->getName(),
				$fieldNames,
			);

			return array_map(
				static fn (string $fieldName): string => $resolver($fieldName),
				$normalized,
			);
		}

		$added = [];

		foreach ($fieldNames as $fieldName) {
			if (isset($this->fieldMap[$fieldName])) {
				$added[] = $fieldName;

				continue;
			}

			$this->fieldMap[$fieldName] = true;
			$this->fieldOrder[] = $fieldName;
			$added[] = $fieldName;
		}

		return $added;
	}

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
	public function getNodeColumns(): array
	{
		return $this->fieldOrder;
	}

	/**
	 * @return list<string>
	 */
	public function getPublicFields(): array
	{
		return $this->publicFieldOrder;
	}

	public function setNode(AbstractNode $node): void
	{
		$this->node = $node;
	}

	public function getNode(): AbstractNode
	{
		return $this->node ?? throw new LogicException('Load branch parser node is not registered.');
	}

	public function getRootNode(): RootNode
	{
		$node = $this->getNode();

		if (! $node instanceof RootNode) {
			throw new LogicException('Load branch parser node is not a root node.');
		}

		return $node;
	}

	public function setPublicNode(AbstractNode $node): void
	{
		$this->publicNode = $node;
	}

	public function getPublicNode(): AbstractNode
	{
		return $this->publicNode ?? $this->getNode();
	}

	public function hasNode(): bool
	{
		return $this->node !== null;
	}

	private function addChild(self $child): void
	{
		$this->children[] = $child;
	}

	/**
	 * @return list<self>
	 */
	public function getChildren(): array
	{
		return $this->children;
	}

	/**
	 * Store the query/source chosen during the initial load-planning stage.
	 */
	public function setQueryContext(
		SelectQuery $query,
		QuerySourceInterface $source,
		?RelationRef $queryLocalRelation,
	): void {
		$this->query = $query;
		$this->source = $source;
		$this->queryLocalRelation = $queryLocalRelation;
	}

	public function getQuery(): SelectQuery
	{
		return $this->query ?? throw new LogicException('Load branch query context is not configured.');
	}

	public function getSource(): QuerySourceInterface
	{
		return $this->source ?? throw new LogicException('Load branch source context is not configured.');
	}

	public function getQueryLocalRelation(): ?RelationRef
	{
		return $this->queryLocalRelation;
	}

	public function setRootFieldResolver(Closure $resolver): void
	{
		$this->rootFieldResolver = $resolver;
	}

	public function addRootPublicColumn(string $alias, ?string $fieldName = null): void
	{
		$this->rootColumns[] = $alias;
		$this->rootValueAliases[] = $alias;
		$this->rootPublicColumns[$alias] = true;

		if ($fieldName !== null) {
			$this->rootFieldParserNames[$fieldName] = $alias;
		}
	}

	public function hasRootFieldParserName(string $fieldName): bool
	{
		return isset($this->rootFieldParserNames[$fieldName]);
	}

	public function getRootFieldParserName(string $fieldName): ?string
	{
		return $this->rootFieldParserNames[$fieldName] ?? null;
	}

	public function setRootFieldParserName(string $fieldName, string $alias): void
	{
		$this->rootFieldParserNames[$fieldName] = $alias;
		$this->rootColumns[] = $alias;
		$this->rootValueAliases[] = $alias;

		if ($this->node instanceof RootNode) {
			$this->node->appendColumns([$alias]);
		}
	}

	/**
	 * @return list<string>
	 */
	public function getRootColumns(): array
	{
		return $this->rootColumns;
	}

	/**
	 * @return list<string>
	 */
	public function getRootValueAliases(): array
	{
		return $this->rootValueAliases;
	}

	/**
	 * @return array<string, true>
	 */
	public function getRootPublicColumns(): array
	{
		return $this->rootPublicColumns;
	}

	/**
	 * @param list<array<string, mixed>> $records
	 * @return list<array<string, mixed>>
	 */
	public function projectRootRecords(array $records): array
	{
		if (! $this->isRoot()) {
			throw new LogicException('Only the root load branch can project root records.');
		}

		$cleaned = [];
		$rootPublicColumns = $this->getRootPublicColumns();

		foreach ($records as $record) {
			$item = [];

			foreach ($record as $key => $value) {
				if (isset($rootPublicColumns[$key])) {
					$item[$key] = $value;
				}
			}

			foreach ($this->getChildren() as $child) {
				$name = $child->getRelation()->getName();
				$value = $record[$name] ?? ($child->isCollectionLike() ? [] : null);

				if ($child->getSelection()->isVisible()) {
					$item[$name] = $child->projectVisibleValue($value);

					continue;
				}

				$this->mergePromotions($item, $child->projectHiddenValue($value), 'root');
			}

			$cleaned[] = $item;
		}

		return $cleaned;
	}

	public function projectVisibleValue(mixed $value): mixed
	{
		if ($this->isCollectionLike()) {
			$projected = [];

			foreach (is_array($value) ? $value : [] as $item) {
				$record = $this->payloadRecord(is_array($item) ? $item : []);

				if ($record === null) {
					continue;
				}

				$projected[] = $this->projectVisibleRecord($record);
			}

			return $projected;
		}

		if ($value === null) {
			return null;
		}

		$record = $this->payloadRecord(is_array($value) ? $value : []);

		return $record === null
			? null
			: $this->projectVisibleRecord($record);
	}

	public function setPublicPayloadChild(?string $container): void
	{
		$this->publicPayloadChild = $container;
	}

	public function getPublicPayloadChild(): ?string
	{
		return $this->publicPayloadChild;
	}

	public function schedule(string $method, SelectQuery $boundaryQuery): void
	{
		$this->scheduledMethod = $method;
		$this->scheduledBoundaryQuery = $boundaryQuery;
	}

	public function clearSchedule(): void
	{
		$this->scheduledMethod = null;
		$this->scheduledBoundaryQuery = null;
	}

	public function getScheduledMethod(): ?string
	{
		return $this->scheduledMethod;
	}

	public function getScheduledBoundaryQuery(): ?SelectQuery
	{
		return $this->scheduledBoundaryQuery;
	}

	public function setJoinedAttachment(bool $joined): void
	{
		$this->joinedAttachment = $joined;
	}

	public function isJoinedAttachment(): bool
	{
		return $this->joinedAttachment ?? throw new LogicException('Load branch attachment mode is not configured.');
	}

	public function isCollectionLike(): bool
	{
		return $this->getNode()->isCollectionLike();
	}

	/**
	 * @param array<string, mixed> $record
	 * @return array<string, mixed>|null
	 */
	private function payloadRecord(array $record): ?array
	{
		$container = $this->publicPayloadChild;

		if ($container === null) {
			return $record;
		}

		$payload = $record[$container] ?? null;

		return is_array($payload) ? $payload : null;
	}

	/**
	 * @param array<string, mixed> $record
	 * @return array<string, mixed>
	 */
	private function projectVisibleRecord(array $record): array
	{
		$item = [];

		if (! $this->isRoot() && $this->getSelection()->isLoaded()) {
			foreach ($this->getPublicFields() as $fieldName) {
				if (array_key_exists($fieldName, $record)) {
					$item[$fieldName] = $record[$fieldName];
				}
			}
		}

		foreach ($this->getChildren() as $child) {
			$name = $child->getRelation()->getName();
			$value = $record[$name] ?? ($child->isCollectionLike() ? [] : null);

			if ($child->getSelection()->isVisible()) {
				$item[$name] = $child->projectVisibleValue($value);

				continue;
			}

			$this->mergePromotions(
				$item,
				$child->projectHiddenValue($value),
				implode('.', $this->getRelation()->getPath()),
			);
		}

		return $item;
	}

	/**
	 * @return array<string, array{branch: self, collection: bool, value: mixed, items: list<array{identity: string, value: mixed}>}>
	 */
	private function projectHiddenValue(mixed $value): array
	{
		if ($this->isCollectionLike()) {
			$promoted = $this->defaultHiddenPromotions(true);

			foreach (is_array($value) ? $value : [] as $item) {
				$this->mergeHiddenCollectionPromotions(
					$promoted,
					$this->projectHiddenRecord(is_array($item) ? $item : []),
				);
			}

			return $promoted;
		}

		if ($value === null) {
			return $this->defaultHiddenPromotions();
		}

		return $this->projectHiddenRecord(is_array($value) ? $value : []);
	}

	/**
	 * @param array<string, mixed> $record
	 * @return array<string, array{branch: self, collection: bool, value: mixed, items: list<array{identity: string, value: mixed}>}>
	 */
	private function projectHiddenRecord(array $record): array
	{
		$promoted = [];
		$payload = $this->payloadRecord($record) ?? [];

		foreach ($this->getChildren() as $child) {
			$name = $child->getRelation()->getName();
			$value = $payload[$name] ?? ($child->isCollectionLike() ? [] : null);

			if ($child->getSelection()->isVisible()) {
				$items = $child->projectPromotionItems($value);
				$promoted[$name] = [
					'branch' => $child,
					'collection' => $child->isCollectionLike(),
					'value' => $child->isCollectionLike()
						? array_column($items, 'value')
						: ($items[0]['value'] ?? null),
					'items' => $items,
				];

				continue;
			}

			$this->mergeHiddenNameMaps($promoted, $child->projectHiddenValue($value));
		}

		return $promoted;
	}

	/**
	 * @param array<string, mixed> $item
	 * @param array<string, array{branch: self, collection: bool, value: mixed, items: list<array{identity: string, value: mixed}>}> $promotions
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
	 * @param array<string, array{branch: self, collection: bool, value: mixed, items: list<array{identity: string, value: mixed}>}> $target
	 * @param array<string, array{branch: self, collection: bool, value: mixed, items: list<array{identity: string, value: mixed}>}> $incoming
	 */
	private function mergeHiddenNameMaps(array &$target, array $incoming): void
	{
		foreach ($incoming as $name => $entry) {
			if (isset($target[$name]) && $target[$name]['branch'] !== $entry['branch']) {
				throw RelationSelectionException::ambiguousPromotion(implode('.', $this->getRelation()->getPath()), $name);
			}

			$target[$name] = $entry;
		}
	}

	/**
	 * @param array<string, array{branch: self, collection: bool, value: mixed, items: list<array{identity: string, value: mixed}>}> $target
	 * @param array<string, array{branch: self, collection: bool, value: mixed, items: list<array{identity: string, value: mixed}>}> $incoming
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
				throw RelationSelectionException::ambiguousPromotion(implode('.', $branch->getRelation()->getPath()), $name);
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
	 * @return array<string, array{branch: self, collection: bool, value: mixed, items: list<array{identity: string, value: mixed}>}>
	 */
	private function defaultHiddenPromotions(bool $forceCollection = false): array
	{
		$promoted = [];

		foreach ($this->getChildren() as $child) {
			$name = $child->getRelation()->getName();

			if ($child->getSelection()->isVisible()) {
				$collection = $forceCollection || $child->isCollectionLike();
				$promoted[$name] = [
					'branch' => $child,
					'collection' => $collection,
					'value' => $collection ? [] : null,
					'items' => [],
				];

				continue;
			}

			foreach ($child->defaultHiddenPromotions($forceCollection || $child->isCollectionLike()) as $childName => $entry) {
				if (isset($promoted[$childName]) && $promoted[$childName]['branch'] !== $entry['branch']) {
					throw RelationSelectionException::ambiguousPromotion(implode('.', $this->getRelation()->getPath()), $childName);
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
		if ($this->isCollectionLike()) {
			$items = [];

			foreach (is_array($value) ? $value : [] as $record) {
				if (! is_array($record)) {
					continue;
				}

				$items[] = [
					'identity' => $this->recordIdentity($record),
					'value' => $this->projectVisibleRecord($record),
				];
			}

			return $items;
		}

		if (! is_array($value)) {
			return [];
		}

		return [[
			'identity' => $this->recordIdentity($value),
			'value' => $this->projectVisibleRecord($value),
		]];
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

	private function recordIdentity(array $value): string
	{
		$identity = [];

		foreach ($this->getRelation()->getCollection()->getPrimaryKey() as $fieldName) {
			$identity[$fieldName] = $value[$fieldName] ?? null;
		}

		return json_encode($identity, JSON_THROW_ON_ERROR);
	}
}
