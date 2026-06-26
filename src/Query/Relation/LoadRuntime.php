<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation;

use LogicException;
use ON\Data\Database\QueryExecutorInterface;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Query\Exception\LoadRuntimeException;
use ON\Data\Query\Exception\RelationSelectionException;
use ON\Data\Query\Expression\AliasedExpression;
use ON\Data\Query\Expression\FieldRef;
use ON\Data\Query\QuerySourceInterface;
use ON\Data\Query\Result\Parser\AbstractNode;
use ON\Data\Query\Result\Parser\CollectionNode;
use ON\Data\Query\Result\Parser\RootNode;
use ON\Data\Query\SelectQuery;
use ReflectionMethod;

final class LoadRuntime
{
	/**
	 * @var array<string, LoadBranch>
	 */
	private array $branches = [];

	private ?LoadBranch $activeBranch = null;

	private ?string $activeMethod = null;

	private bool $registering = false;

	private bool $scheduledInInvocation = false;

	private ?SelectQuery $schedulingBoundaryQuery = null;

	/**
	 * @var array<int, SelectQuery>
	 */
	private array $pendingBoundaryQueries = [];

	private int $loaderInvocationDepth = 0;

	private bool $flushingPendingBoundaries = false;

	private int $aliasCounter = 0;

	private ?RootNode $rootNode = null;

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

	public function __construct(
		private readonly SelectQuery $rootQuery,
		private readonly QueryExecutorInterface $executor,
	) {
	}

	public function fetchAll(): array
	{
		$this->buildPlan();
		$this->parseRootRows($this->executor->fetchAll($this->rootQuery));
		$this->completeBoundary($this->rootQuery);

		return $this->cleanupRootRecords($this->requireRootNode()->getResult());
	}

	public function fetchOne(): ?array
	{
		$this->buildPlan();
		$row = $this->executor->fetchOne($this->rootQuery);

		if ($row === null) {
			return null;
		}

		$this->parseRootRows([$row]);
		$this->completeBoundary($this->rootQuery);

		return $this->cleanupRootRecords($this->requireRootNode()->getResult())[0] ?? null;
	}

	/**
	 * @param non-empty-list<string> $fieldNames
	 * @return non-empty-list<string>
	 */
	public function requireBranchFields(array $fieldNames): array
	{
		$branch = $this->requireActiveBranch();
		$collection = $branch->getRelation()->getCollection();

		return $branch->addFields($this->normalizeFieldNames($collection, $fieldNames));
	}

	/**
	 * @param non-empty-list<string> $fieldNames
	 * @return non-empty-list<string>
	 */
	public function requireParentFields(array $fieldNames): array
	{
		$branch = $this->requireActiveBranch();
		$parent = $branch->getParent();
		$collection = $parent?->getRelation()->getCollection() ?? $this->rootQuery->getCollection();
		$normalized = $this->normalizeFieldNames($collection, $fieldNames);

		if ($parent === null) {
			return array_map($this->ensureRootFieldParserName(...), $normalized);
		}

		return $parent->addFields($normalized);
	}

	/**
	 * @return list<string>
	 */
	public function getNodeColumns(): array
	{
		return $this->requireActiveBranch()->getNodeColumns();
	}

	public function getNode(): AbstractNode
	{
		return $this->requireActiveBranch()->getNode();
	}

	public function getParentNode(): AbstractNode
	{
		$branch = $this->requireActiveBranch();
		$parent = $branch->getParent();

		return $parent?->getNode() ?? $this->requireRootNode();
	}

	public function setJoinedAttachment(bool $joined): void
	{
		$this->requireActiveBranch()->setJoinedAttachment($joined);
	}

	public function getQuery(): SelectQuery
	{
		return $this->requireActiveBranch()->getQuery();
	}

	public function getSource(): QuerySourceInterface
	{
		return $this->requireActiveBranch()->getSource();
	}

	public function getQueryRelation(): RelationRef
	{
		$branch = $this->requireActiveBranch();
		$parent = $branch->getParent();
		$query = $parent?->getQuery() ?? $this->rootQuery;

		if ($parent === null || $parent->getQueryLocalRelation() === null) {
			return $query->relation($branch->getRelation()->getName());
		}

		return $parent->getQueryLocalRelation()
			->relation($branch->getRelation()->getName());
	}

	public function createQuery(CollectionInterface $collection): SelectQuery
	{
		return $this->rootQuery->related($collection);
	}

	public function setQueryContext(
		SelectQuery $query,
		QuerySourceInterface $source,
		?RelationRef $queryLocalRelation = null,
	): void {
		$this->requireActiveBranch()->setQueryContext($query, $source, $queryLocalRelation);
	}

	/**
	 * @return list<array<string, scalar>>
	 */
	public function getReferenceValues(): array
	{
		return $this->requireActiveBranch()->getNode()->getReferenceValues();
	}

	public function nextPass(string $method = 'load'): void
	{
		if ($this->registering) {
			throw LoadRuntimeException::nextPassNotAllowedDuringRegister($this->requireActiveBranch()->getRelation());
		}

		if ($this->scheduledInInvocation) {
			throw LoadRuntimeException::multipleNextPasses($this->requireActiveBranch()->getRelation(), $this->activeMethod ?? 'load');
		}

		$this->assertSchedulableMethod($method);
		$this->requireActiveBranch()->schedule(
			$method,
			$this->schedulingBoundaryQuery ?? throw LoadRuntimeException::scheduleBoundaryMissing($this->requireActiveBranch()->getRelation()),
		);
		$this->scheduledInInvocation = true;
	}

	public function execute(SelectQuery $query): void
	{
		$branch = $this->requireActiveBranch();
		$rows = $this->executor->fetchAll($query);

		if ($query === $branch->getQuery()) {
			foreach ($rows as $row) {
				$branch->getNode()->parseRow(0, $this->orderedValues($row, $this->branchAliasTraversal($branch)));
			}
		}

		$this->schedulingBoundaryQuery = $query;
		$this->pendingBoundaryQueries[spl_object_id($query)] = $query;
	}

	public function getLoadStrategy(LoadStrategy $default): LoadStrategy
	{
		return $default;
	}

	public function registerChildBranches(): void
	{
		$parent = $this->requireActiveBranch();

		foreach ($this->childBranches($parent) as $child) {
			$this->registerBranch($child);
		}
	}

	/**
	 * @return list<LoadBranch>
	 */
	public function getChildBranches(): array
	{
		return $this->childBranches($this->requireActiveBranch());
	}

	private function buildPlan(): void
	{
		if ($this->rootNode instanceof RootNode) {
			return;
		}

		$this->planRootSelections();
		$this->buildBranchSkeletons();
		$this->loadBranches();
		$this->rootNode = $this->registerParserTree();
		$this->finalizeBranchSelections();
	}

	private function planRootSelections(): void
	{
		$publicSelections = array_values(array_filter(
			$this->rootQuery->getSelections()->getExplicit(),
			fn ($selection): bool => ! $this->isInternalSelection($selection->getExpression()),
		));

		if ($publicSelections === []) {
			foreach ($this->rootQuery->getCollection()->getVisibleFields() as $fieldName) {
				$field = $this->rootQuery->field($fieldName);
				$this->rootQuery->select($field);
				$publicSelections = $this->rootQuery->getSelections()->getExplicit();
			}
		}

		foreach ($publicSelections as $selection) {
			$expression = $selection->getExpression();
			$alias = $expression instanceof AliasedExpression
				? $expression->getAlias()
				: implode('.', $expression->getPath());

			$this->rootColumns[] = $alias;
			$this->rootValueAliases[] = $alias;
			$this->rootPublicColumns[$alias] = true;

			$fieldExpression = $expression instanceof AliasedExpression
				? $expression->getExpression()
				: $expression;

			if ($fieldExpression instanceof FieldRef && $fieldExpression->getSource() === $this->rootQuery) {
				$this->rootFieldParserNames[$fieldExpression->getField()->getName()] = $alias;
			}
		}

		foreach ($this->rootPrimaryKeyFields() as $fieldName) {
			$this->ensureRootFieldParserName($fieldName);
		}
	}

	private function buildBranchSkeletons(): void
	{
		foreach ($this->rootQuery->getRelationSelections()->getAll() as $selection) {
			$key = $this->branchKey($selection->getPath());
			$parent = $selection->getParentPathKey() === null
				? null
				: $this->branches[$selection->getParentPathKey()] ?? throw LoadRuntimeException::parentBranchMissing($selection->getRelation());

			$this->branches[$key] = new LoadBranch(
				$selection,
				$parent,
				$selection->getRelation()->getLoader(),
				$selection->isLoaded()
					? $this->collectionFieldNames($selection->getRelation()->getCollection())
					: [],
			);
		}
	}

	private function loadBranches(): void
	{
		$branches = array_values($this->branches);
		usort($branches, static fn (LoadBranch $left, LoadBranch $right): int => count($left->getRelation()->getPath()) <=> count($right->getRelation()->getPath()));

		foreach ($branches as $branch) {
			$boundary = $branch->getParent()?->getQuery() ?? $this->rootQuery;
			$this->invokeLoaderMethod($branch, 'load', $boundary);

			if ($branch->getQuery()->getCollection()->getName() === '') {
				throw LoadRuntimeException::queryNotConfigured($branch->getRelation());
			}
		}
	}

	private function registerParserTree(): RootNode
	{
		foreach ($this->rootBranches() as $branch) {
			$this->registerBranch($branch);
		}

		$rootNode = new RootNode($this->rootColumns, $this->rootIdentityFields());

		foreach ($this->rootBranches() as $branch) {
			$node = $branch->getNode();

			if ($branch->isJoinedAttachment()) {
				$rootNode->joinNode($branch->getRelation()->getName(), $node);

				continue;
			}

			$rootNode->linkNode($branch->getRelation()->getName(), $node);
		}

		return $rootNode;
	}

	private function finalizeBranchSelections(): void
	{
		$branches = array_values($this->branches);
		usort($branches, static fn (LoadBranch $left, LoadBranch $right): int => count($left->getRelation()->getPath()) <=> count($right->getRelation()->getPath()));

		foreach ($branches as $branch) {
			$aliases = [];

			foreach ($branch->getNodeColumns() as $fieldName) {
				$aliases[] = $this->ensureBranchFieldSelection(
					$branch,
					$branch->getQuery(),
					$branch->getSource(),
					$fieldName,
				);
			}

			$branch->setNodeValueAliases($aliases);
		}
	}

	private function registerBranch(LoadBranch $branch): AbstractNode
	{
		$previousBranch = $this->activeBranch;
		$previousMethod = $this->activeMethod;
		$previousRegistering = $this->registering;
		$this->activeBranch = $branch;
		$this->activeMethod = 'register';
		$this->registering = true;

		try {
			$node = $branch->getLoader()->register($branch->getRelation(), $this);
		} finally {
			$this->registering = $previousRegistering;
			$this->activeMethod = $previousMethod;
			$this->activeBranch = $previousBranch;
		}

		if (! $node instanceof AbstractNode) {
			throw LoadRuntimeException::nodeNotRegistered($branch->getRelation());
		}

		$branch->setNode($node);

		return $node;
	}

	private function invokeLoaderMethod(LoadBranch $branch, string $method, SelectQuery $boundaryQuery): void
	{
		$loader = $branch->getLoader();
		$previousBranch = $this->activeBranch;
		$previousMethod = $this->activeMethod;
		$previousScheduledInInvocation = $this->scheduledInInvocation;
		$previousSchedulingBoundaryQuery = $this->schedulingBoundaryQuery;
		$this->activeBranch = $branch;
		$this->activeMethod = $method;
		$this->scheduledInInvocation = false;
		$this->schedulingBoundaryQuery = $boundaryQuery;
		$this->loaderInvocationDepth++;
		$branch->clearSchedule();

		try {
			$loader->{$method}($branch->getRelation(), $this);
		} finally {
			$this->loaderInvocationDepth--;
			$this->activeBranch = $previousBranch;
			$this->activeMethod = $previousMethod;
			$this->scheduledInInvocation = $previousScheduledInInvocation;
			$this->schedulingBoundaryQuery = $previousSchedulingBoundaryQuery;
		}

		if ($this->loaderInvocationDepth === 0) {
			$this->flushPendingBoundaries();
		}
	}

	private function completeBoundary(SelectQuery $query): void
	{
		do {
			$ran = false;

			foreach ($this->branches as $branch) {
				if ($branch->getScheduledBoundaryQuery() !== $query) {
					continue;
				}

				$method = $branch->getScheduledMethod();

				if ($method === null) {
					continue;
				}

				$branch->clearSchedule();
				$this->invokeLoaderMethod($branch, $method, $query);
				$ran = true;
			}
		} while ($ran);
	}

	private function flushPendingBoundaries(): void
	{
		if ($this->flushingPendingBoundaries) {
			return;
		}

		$this->flushingPendingBoundaries = true;

		try {
			while ($this->pendingBoundaryQueries !== []) {
				$query = array_shift($this->pendingBoundaryQueries);

				if (! $query instanceof SelectQuery) {
					continue;
				}

				$this->completeBoundary($query);
			}
		} finally {
			$this->flushingPendingBoundaries = false;
		}
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 */
	private function parseRootRows(array $rows): void
	{
		$rootNode = $this->requireRootNode();
		$aliases = $this->rootAliasTraversal();

		foreach ($rows as $row) {
			$rootNode->parseRow(0, $this->orderedValues($row, $aliases));
		}
	}

	/**
	 * @param array<string, mixed> $row
	 * @param list<string> $aliases
	 * @return list<mixed>
	 */
	private function orderedValues(array $row, array $aliases): array
	{
		$ordered = [];

		foreach ($aliases as $alias) {
			$ordered[] = $row[$alias] ?? null;
		}

		return $ordered;
	}

	/**
	 * @param list<array<string, mixed>> $records
	 * @return list<array<string, mixed>>
	 */
	private function cleanupRootRecords(array $records): array
	{
		$cleaned = [];

		foreach ($records as $record) {
			$item = [];

			foreach ($record as $key => $value) {
				if (isset($this->rootPublicColumns[$key])) {
					$item[$key] = $value;
				}
			}

			foreach ($this->rootBranches() as $branch) {
				$name = $branch->getRelation()->getName();
				$value = $record[$name] ?? ($this->isCollectionBranch($branch) ? [] : null);

				if ($branch->getSelection()->isVisible()) {
					$item[$name] = $this->projectVisibleBranch($branch, $value);

					continue;
				}

				$this->mergePromotions(
					$item,
					$this->projectHiddenBranch($branch, $value),
					'root',
				);
			}

			$cleaned[] = $item;
		}

		return $cleaned;
	}

	private function projectVisibleBranch(LoadBranch $branch, mixed $value): mixed
	{
		if ($this->isCollectionBranch($branch)) {
			$projected = [];

			foreach (is_array($value) ? $value : [] as $item) {
				$projected[] = $this->projectVisibleRecord($branch, is_array($item) ? $item : []);
			}

			return $projected;
		}

		if ($value === null) {
			return null;
		}

		return $this->projectVisibleRecord($branch, is_array($value) ? $value : []);
	}

	/**
	 * @param array<string, mixed> $record
	 * @return array<string, mixed>
	 */
	private function projectVisibleRecord(LoadBranch $branch, array $record): array
	{
		$item = [];

		if ($branch->getSelection()->isLoaded()) {
			foreach ($branch->getRelation()->getCollection()->getVisibleFields() as $fieldName) {
				if (array_key_exists($fieldName, $record)) {
					$item[$fieldName] = $record[$fieldName];
				}
			}
		}

		foreach ($this->childBranches($branch) as $child) {
			$name = $child->getRelation()->getName();
			$value = $record[$name] ?? ($this->isCollectionBranch($child) ? [] : null);

			if ($child->getSelection()->isVisible()) {
				$item[$name] = $this->projectVisibleBranch($child, $value);

				continue;
			}

			$this->mergePromotions(
				$item,
				$this->projectHiddenBranch($child, $value),
				implode('.', $branch->getRelation()->getPath()),
			);
		}

		return $item;
	}

	/**
	 * @return array<string, array{branch: LoadBranch, collection: bool, value: mixed, items: list<array{identity: string, value: mixed}>}>
	 */
	private function projectHiddenBranch(LoadBranch $branch, mixed $value): array
	{
		if ($this->isCollectionBranch($branch)) {
			$promoted = $this->defaultHiddenPromotions($branch, true);

			foreach (is_array($value) ? $value : [] as $item) {
				$this->mergeHiddenCollectionPromotions(
					$promoted,
					$this->projectHiddenRecord($branch, is_array($item) ? $item : []),
				);
			}

			return $promoted;
		}

		if ($value === null) {
			return $this->defaultHiddenPromotions($branch);
		}

		return $this->projectHiddenRecord($branch, is_array($value) ? $value : []);
	}

	/**
	 * @param array<string, mixed> $record
	 * @return array<string, array{branch: LoadBranch, collection: bool, value: mixed, items: list<array{identity: string, value: mixed}>}>
	 */
	private function projectHiddenRecord(LoadBranch $branch, array $record): array
	{
		$promoted = [];

		foreach ($this->childBranches($branch) as $child) {
			$name = $child->getRelation()->getName();
			$value = $record[$name] ?? ($this->isCollectionBranch($child) ? [] : null);

			if ($child->getSelection()->isVisible()) {
				$items = $this->projectPromotionItems($child, $value);
				$promoted[$name] = [
					'branch' => $child,
					'collection' => $this->isCollectionBranch($child),
					'value' => $this->isCollectionBranch($child)
						? array_column($items, 'value')
						: ($items[0]['value'] ?? null),
					'items' => $items,
				];

				continue;
			}

			$this->mergeHiddenNameMaps($promoted, $this->projectHiddenBranch($child, $value), $branch);
		}

		return $promoted;
	}

	/**
	 * @param array<string, mixed> $item
	 * @param array<string, array{branch: LoadBranch, collection: bool, value: mixed, items: list<array{identity: string, value: mixed}>}> $promotions
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
	 * @param array<string, array{branch: LoadBranch, collection: bool, value: mixed, items: list<array{identity: string, value: mixed}>}> $target
	 * @param array<string, array{branch: LoadBranch, collection: bool, value: mixed, items: list<array{identity: string, value: mixed}>}> $incoming
	 */
	private function mergeHiddenNameMaps(array &$target, array $incoming, LoadBranch $hiddenBranch): void
	{
		foreach ($incoming as $name => $entry) {
			if (isset($target[$name]) && $target[$name]['branch'] !== $entry['branch']) {
				throw RelationSelectionException::ambiguousPromotion(implode('.', $hiddenBranch->getRelation()->getPath()), $name);
			}

			$target[$name] = $entry;
		}
	}

	/**
	 * @param array<string, array{branch: LoadBranch, collection: bool, value: mixed, items: list<array{identity: string, value: mixed}>}> $target
	 * @param array<string, array{branch: LoadBranch, collection: bool, value: mixed, items: list<array{identity: string, value: mixed}>}> $incoming
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
	 * @return array<string, array{branch: LoadBranch, collection: bool, value: mixed, items: list<array{identity: string, value: mixed}>}>
	 */
	private function defaultHiddenPromotions(LoadBranch $branch, bool $forceCollection = false): array
	{
		$promoted = [];

		foreach ($this->childBranches($branch) as $child) {
			$name = $child->getRelation()->getName();

			if ($child->getSelection()->isVisible()) {
				$collection = $forceCollection || $this->isCollectionBranch($child);
				$promoted[$name] = [
					'branch' => $child,
					'collection' => $collection,
					'value' => $collection ? [] : null,
					'items' => [],
				];

				continue;
			}

			foreach ($this->defaultHiddenPromotions($child, $forceCollection || $this->isCollectionBranch($child)) as $childName => $entry) {
				if (isset($promoted[$childName]) && $promoted[$childName]['branch'] !== $entry['branch']) {
					throw RelationSelectionException::ambiguousPromotion(implode('.', $branch->getRelation()->getPath()), $childName);
				}

				$promoted[$childName] = $entry;
			}
		}

		return $promoted;
	}

	/**
	 * @return list<array{identity: string, value: mixed}>
	 */
	private function projectPromotionItems(LoadBranch $branch, mixed $value): array
	{
		if ($this->isCollectionBranch($branch)) {
			$items = [];

			foreach (is_array($value) ? $value : [] as $record) {
				if (! is_array($record)) {
					continue;
				}

				$items[] = [
					'identity' => $this->recordIdentity($record, $branch),
					'value' => $this->projectVisibleRecord($branch, $record),
				];
			}

			return $items;
		}

		if (! is_array($value)) {
			return [];
		}

		return [[
			'identity' => $this->recordIdentity($value, $branch),
			'value' => $this->projectVisibleRecord($branch, $value),
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

	private function recordIdentity(array $value, LoadBranch $branch): string
	{
		$identity = [];

		foreach ($branch->getRelation()->getCollection()->getPrimaryKey() as $fieldName) {
			$identity[$fieldName] = $value[$fieldName] ?? null;
		}

		return json_encode($identity, JSON_THROW_ON_ERROR);
	}

	/**
	 * @return list<string>
	 */
	private function collectionFieldNames(CollectionInterface $collection): array
	{
		$fields = [];

		foreach ($collection->getFields() as $field) {
			$fields[] = $field->getName();
		}

		return $fields;
	}

	/**
	 * @param non-empty-list<string> $fieldNames
	 * @return non-empty-list<string>
	 */
	private function normalizeFieldNames(CollectionInterface $collection, array $fieldNames): array
	{
		return array_map(
			static fn (string $fieldName): string => $collection->getField($fieldName)->getName(),
			$fieldNames,
		);
	}

	/**
	 * @return non-empty-list<string>
	 */
	private function rootIdentityFields(): array
	{
		return array_map(
			$this->ensureRootFieldParserName(...),
			$this->rootPrimaryKeyFields(),
		);
	}

	/**
	 * @return non-empty-list<string>
	 */
	private function rootPrimaryKeyFields(): array
	{
		return $this->normalizeFieldNames($this->rootQuery->getCollection(), $this->rootQuery->getCollection()->getPrimaryKey());
	}

	private function requireActiveBranch(): LoadBranch
	{
		return $this->activeBranch ?? throw LoadRuntimeException::activeBranchMissing();
	}

	private function requireRootNode(): RootNode
	{
		return $this->rootNode ?? throw new LogicException('LoadRuntime root node is not built.');
	}

	/**
	 * @param list<string> $path
	 */
	private function branchKey(array $path): string
	{
		return json_encode($path, JSON_THROW_ON_ERROR);
	}

	/**
	 * @return list<LoadBranch>
	 */
	private function rootBranches(): array
	{
		return array_values(array_filter(
			$this->branches,
			static fn (LoadBranch $branch): bool => $branch->getParent() === null,
		));
	}

	/**
	 * @return list<LoadBranch>
	 */
	private function childBranches(LoadBranch $branch): array
	{
		return array_values(array_filter(
			$this->branches,
			static fn (LoadBranch $child): bool => $child->getParent() === $branch,
		));
	}

	private function isCollectionBranch(LoadBranch $branch): bool
	{
		return $branch->getNode() instanceof CollectionNode;
	}

	private function ensureBranchFieldSelection(
		LoadBranch $branch,
		SelectQuery $query,
		QuerySourceInterface $source,
		string $fieldName,
	): string {
		if ($source === $query) {
			$query->select($query->field($fieldName));

			return $fieldName;
		}

		$alias = $this->allocateAlias($branch->getRelation()->getPath(), $fieldName);

		if (! $query->getSelections()->hasNamedExpression($alias)) {
			$query->select($source->field($fieldName)->as($alias));
		}

		return $alias;
	}

	/**
	 * @param list<string> $path
	 */
	private function allocateAlias(array $path, string $fieldName): string
	{
		return sprintf(
			'__on_data_%s_%d',
			strtolower(preg_replace('/[^a-z0-9_]+/i', '_', implode('_', [...$path, $fieldName])) ?? 'field'),
			$this->aliasCounter++,
		);
	}

	private function ensureRootFieldParserName(string $fieldName): string
	{
		if (isset($this->rootFieldParserNames[$fieldName])) {
			return $this->rootFieldParserNames[$fieldName];
		}

		$alias = $this->allocateAlias(['root', 'required'], $fieldName);

		if (! $this->rootQuery->getSelections()->hasNamedExpression($alias)) {
			$this->rootQuery->select($this->rootQuery->field($fieldName)->as($alias));
		}

		$this->rootFieldParserNames[$fieldName] = $alias;
		$this->rootColumns[] = $alias;
		$this->rootValueAliases[] = $alias;

		return $alias;
	}

	/**
	 * @return list<string>
	 */
	private function rootAliasTraversal(): array
	{
		$aliases = $this->rootValueAliases;

		foreach ($this->rootBranches() as $branch) {
			if ($branch->getQuery() !== $this->rootQuery) {
				continue;
			}

			array_push($aliases, ...$this->branchAliasTraversal($branch));
		}

		return $aliases;
	}

	/**
	 * @return list<string>
	 */
	private function branchAliasTraversal(LoadBranch $branch): array
	{
		$aliases = $branch->getNodeValueAliases();

		foreach ($this->childBranches($branch) as $child) {
			if ($child->getQuery() !== $branch->getQuery()) {
				continue;
			}

			array_push($aliases, ...$this->branchAliasTraversal($child));
		}

		return $aliases;
	}

	private function assertSchedulableMethod(string $method): void
	{
		$loader = $this->requireActiveBranch()->getLoader();

		if ($method === 'register' || $method === 'join') {
			throw LoadRuntimeException::invalidScheduledMethod($this->requireActiveBranch()->getRelation(), $method);
		}

		if (! method_exists($loader, $method)) {
			throw LoadRuntimeException::invalidScheduledMethod($this->requireActiveBranch()->getRelation(), $method);
		}

		$reflection = new ReflectionMethod($loader, $method);

		if (! $reflection->isPublic() || $reflection->getNumberOfParameters() !== 2) {
			throw LoadRuntimeException::invalidScheduledMethod($this->requireActiveBranch()->getRelation(), $method);
		}
	}

	private function isInternalSelection(mixed $expression): bool
	{
		return $expression instanceof AliasedExpression
			&& str_starts_with($expression->getAlias(), '__on_data_');
	}
}
