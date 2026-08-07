<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation;

use LogicException;
use ON\Data\Database\QueryExecutorInterface;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\ORM\Representation\Schema\RepresentationSchema;
use ON\Data\Query\Exception\LoadRuntimeException;
use ON\Data\Query\Exception\RelationSelectionException;
use ON\Data\Query\Expression\AliasedExpression;
use ON\Data\Query\Expression\FieldRef;
use ON\Data\Query\QuerySourceInterface;
use ON\Data\Query\Result\Parser\AbstractNode;
use ON\Data\Query\Selection\SelectionItem;
use ON\Data\Query\Selection\SelectionTag;
use ON\Data\Query\SelectQuery;
use ReflectionMethod;

final class LoadRuntime
{
	/**
	 * @var array<string, RelationLoadBranch>
	 */
	private array $branches = [];

	private ?string $activeMethod = null;

	private bool $registering = false;

	private bool $continuationRequested = false;

	private ?SelectQuery $currentContinuationQuery = null;

	/**
	 * @var array<int, SelectQuery>
	 */
	private array $pendingContinuationQueries = [];

	private int $loaderInvocationDepth = 0;

	private bool $flushingPendingContinuations = false;

	private RootLoadBranch $rootBranch;

	private RelationOutputProcessor $outputProcessor;

	public function __construct(
		private readonly SelectQuery $rootQuery,
		private readonly QueryExecutorInterface $executor,
		?RepresentationSchema $schema = null,
	) {
		$this->rootBranch = new RootLoadBranch(
			$rootQuery,
			fn (string $fieldName): string => $this->stableJoinedAlias(['root', 'required'], $fieldName),
		);
		$this->outputProcessor = new RelationOutputProcessor($schema);
	}

	public function fetchAll(): array
	{
		if ($this->canShortCircuitRootFetch()) {
			return $this->executor->fetchAll($this->rootQuery);
		}

		$this->prepare();
		$this->rootBranch->parseRows($this->executor->fetchAll($this->rootQuery));
		$this->runContinuationsFor($this->rootQuery);

		return $this->outputProcessor->processRoot($this->rootBranch);
	}

	public function fetchOne(): ?array
	{
		if ($this->canShortCircuitRootFetch()) {
			return $this->executor->fetchOne($this->rootQuery);
		}

		$this->prepare();
		$row = $this->executor->fetchOne($this->rootQuery);

		if ($row === null) {
			return null;
		}

		$this->rootBranch->parseRows([$row]);
		$this->runContinuationsFor($this->rootQuery);

		return $this->outputProcessor->processRoot($this->rootBranch)[0] ?? null;
	}

	/**
	 * Skip root parser/assemble when there are no relation loads and every root
	 * field already uses place≡load keys (legacy simple fetch path).
	 */
	private function canShortCircuitRootFetch(): bool
	{
		if (! $this->rootQuery->getRelationSelections()->isEmpty()) {
			return false;
		}

		foreach ($this->rootQuery->getSelections()->getExplicit() as $selection) {
			if ($selection->hasTag(SelectionTag::SQL_ONLY)) {
				continue;
			}

			$fieldRef = $this->columnFieldRef($selection);

			if (! $fieldRef instanceof FieldRef) {
				continue;
			}

			$placeKey = $selection->getSelectionKey();
			$loadKey = $this->levelLoadKey(
				$this->rootQuery,
				$selection,
				$fieldRef,
				$fieldRef->getField()->getName(),
			);

			if ($placeKey !== $loadKey) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @return iterable<array<string, mixed>>
	 */
	public function iterate(): iterable
	{
		if (! $this->rootQuery->getRelationSelections()->isEmpty()) {
			throw RelationSelectionException::iterateNotSupported();
		}

		return $this->executor->iterate($this->rootQuery);
	}

	public function getQueryRelation(RelationLoadBranch $branch): RelationRef
	{
		$parent = $branch->getParent();
		$query = $parent->getQuery();

		if ($parent->getQueryLocalRelation() === null) {
			return $query->relation($branch->getRelationRef()->getName());
		}

		return $parent->getQueryLocalRelation()
			->relation($branch->getRelationRef()->getName());
	}

	public function createQuery(CollectionInterface $collection): SelectQuery
	{
		return $this->rootQuery->related($collection);
	}

	public function setQueryContext(
		RelationLoadBranch $branch,
		SelectQuery $query,
		QuerySourceInterface $source,
		?RelationRef $queryLocalRelation = null,
	): void {
		$branch->setQueryContext($query, $source, $queryLocalRelation);
	}

	public function continueWith(RelationLoadBranch $branch, string $method = 'load'): void
	{
		if ($this->registering) {
			throw LoadRuntimeException::continuationNotAllowedDuringRegister($branch->getRelationRef());
		}

		if ($this->continuationRequested) {
			throw LoadRuntimeException::multipleContinuations($branch->getRelationRef(), $this->activeMethod ?? 'load');
		}

		$this->assertContinuableMethod($branch, $method);
		$branch->setContinuation(
			$method,
			$this->currentContinuationQuery ?? throw LoadRuntimeException::continuationQueryMissing($branch->getRelationRef()),
		);
		$this->continuationRequested = true;
	}

	public function execute(RelationLoadBranch $branch, SelectQuery $query): void
	{
		$rows = $this->executor->fetchAll($query);

		foreach ($rows as $row) {
			$branch->getNode()->parseRow(
				0,
				LoadBranch::orderedValues($row, $branch->getNode()->getValueAliasTraversal()),
			);
		}

		$continuationQuery = $branch->getQuery();
		$this->currentContinuationQuery = $continuationQuery;
		$this->pendingContinuationQueries[spl_object_id($continuationQuery)] = $continuationQuery;
	}

	public function getLoadStrategy(RelationLoadBranch $branch): LoadStrategy
	{
		return $branch->getSelection()->getStrategy()
			?? $branch->getLoader()->getDefaultLoadStrategy();
	}

	/**
	 * Register required fields and, when query context exists, emit SQL columns now.
	 *
	 * @param list<string> $fieldNames
	 * @return list<string> load-local parser keys
	 */
	public function requireFields(LoadBranch $branch, array $fieldNames): array
	{
		if ($branch instanceof RootLoadBranch) {
			// Root already emits SQL and returns load-local keys.
			return $branch->requireFields($fieldNames);
		}

		$placeKeys = $branch->requireFields($fieldNames);

		if (! $branch->hasQueryContext() || $fieldNames === []) {
			return array_map(
				static fn (string $placeKey): string => $branch->loadKeyForPlace($placeKey),
				$placeKeys,
			);
		}

		$level = $branch->getProjectionLevel();
		$ownsQuery = $branch->getSource() === $branch->getQuery();
		$loadKeys = [];

		foreach ($placeKeys as $index => $placeKey) {
			$fieldName = $fieldNames[$index] ?? $placeKey;
			$normalized = $branch->getCollection()->getField($fieldName)->getName();
			$fieldRef = $level instanceof RelationRef
				? $level->field($normalized)
				: $branch->getQuery()->field($normalized);
			$path = $level instanceof RelationRef ? $level->getPath() : [];
			$preferred = $branch->loadKeyForPlace($placeKey);
			if ($preferred === $placeKey) {
				// SEPARATE/root-owned queries can use the field name; JOIN onto a
				// shared parent query needs a path-stable alias (parent may already
				// expose the same field name).
				$preferred = $ownsQuery
					? $normalized
					: $this->stableJoinedAlias($path, $normalized);
			}
			$sqlKey = $this->ensureLevelFieldSelection($branch, $fieldRef, $placeKey, $preferred, $path);
			$branch->bindPlaceToLoadKey($placeKey, $sqlKey);
			$loadKeys[] = $sqlKey;
		}

		return $loadKeys;
	}

	private function prepare(): void
	{
		if ($this->rootBranch->hasNode()) {
			return;
		}

		$this->rootBranch->registerPublicSelections();
		$this->rootBranch->requirePrimaryKey();
		// Own-level root columns before relation load (loaders may inspect the root query).
		$this->selectLevelFields($this->rootBranch, includeCrossLevelFlats: false);
		$this->createBranches();
		$this->configureBranches();

		// Flats after destinations exist so loaded to-one children can be reused.
		$this->selectLevelFields($this->rootBranch, includeCrossLevelFlats: true);

		foreach ($this->relationBranchesByDepth() as $branch) {
			$this->selectLevelFields($branch, includeCrossLevelFlats: true);
		}

		$this->createParserTree();

		$this->selectLevelFields($this->rootBranch, includeCrossLevelFlats: true);

		foreach ($this->relationBranchesByDepth() as $branch) {
			$this->selectLevelFields($branch, includeCrossLevelFlats: true);
		}
	}

	private function createBranches(): void
	{
		foreach ($this->rootQuery->getRelationSelections()->getAll() as $selection) {
			$key = $this->branchKey($selection->getPath());
			$parent = $selection->getParentPathKey() === null
				? $this->rootBranch
				: $this->branches[$selection->getParentPathKey()] ?? throw LoadRuntimeException::parentBranchMissing($selection->getRelationRef());

			$this->branches[$key] = new RelationLoadBranch($selection, $parent, $selection->getRelationRef()->getLoader());
		}
	}

	private function configureBranches(): void
	{
		foreach ($this->relationBranchesByDepth() as $branch) {
			$query = $branch->getParent()->getQuery();
			$this->invokeLoaderMethod($branch, 'load', $query);

			if ($branch->getQuery()->getCollection()->getName() === '') {
				throw LoadRuntimeException::queryNotConfigured($branch->getRelationRef());
			}
		}
	}

	/**
	 * @return list<RelationLoadBranch>
	 */
	private function relationBranchesByDepth(): array
	{
		$branches = array_values($this->branches);
		usort(
			$branches,
			static fn (RelationLoadBranch $left, RelationLoadBranch $right): int => count($left->getRelationRef()->getPath()) <=> count($right->getRelationRef()->getPath()),
		);

		return $branches;
	}

	private function createParserTree(): void
	{
		$rootNode = $this->rootBranch->createNode();

		foreach ($this->rootBranch->getChildren() as $branch) {
			$this->registerBranch($branch);
		}

		foreach ($this->rootBranch->getChildren() as $branch) {
			$node = $branch->getNode();

			if ($branch->isJoinedAttachment()) {
				$rootNode->joinNode($branch->getRelationRef()->getName(), $node);

				continue;
			}

			$rootNode->linkNode($branch->getRelationRef()->getName(), $node);
		}
	}

	/**
	 * Bind place keys to load-local SQL/parser keys for one projection level.
	 *
	 * Flat related fields join under this destination unless a loaded to-one child
	 * destination already covers that source path — then require the field there
	 * and assemble from the child bag (no redundant parent JOIN).
	 *
	 * @param bool $includeCrossLevelFlats when false, skip FieldRefs whose source is
	 *        under this level (used for the early root pass before child branches exist)
	 */
	private function selectLevelFields(LoadBranch $branch, bool $includeCrossLevelFlats = true): void
	{
		$level = $branch->getProjectionLevel();
		$aliases = [];

		foreach ($branch->getSelections()->getByTag(SelectionTag::COLUMN) as $selection) {
			$fieldRef = $this->columnFieldRef($selection);
			$placeKey = $selection->getSelectionKey();

			if (! $fieldRef instanceof FieldRef) {
				$branch->bindPlaceToLoadKey($placeKey, $placeKey);
				$aliases[] = $placeKey;

				continue;
			}

			$fieldName = $fieldRef->getField()->getName();
			$source = $fieldRef->getSource();
			$isCrossLevelFlat = $source instanceof RelationRef && $source->isUnder($level);

			if ($isCrossLevelFlat && ! $includeCrossLevelFlats) {
				continue;
			}

			if ($isCrossLevelFlat) {
				$relative = $source->relativeTo($level);
				$destination = $this->findLoadedToOneChildDestination($branch, $relative);

				if ($destination instanceof RelationLoadBranch) {
					$loadKeys = $this->requireFields($destination, [$fieldName]);
					$branch->bindPlaceToChildDestination($placeKey, $relative, $loadKeys[0] ?? $fieldName);

					continue;
				}
			}

			$path = $source instanceof RelationRef
				? $source->getPath()
				: ($level instanceof RelationRef ? $level->getPath() : []);
			$loadKey = $this->levelLoadKey($level, $selection, $fieldRef, $fieldName);
			$sqlKey = $this->ensureLevelFieldSelection($branch, $fieldRef, $placeKey, $loadKey, $path);
			$branch->bindPlaceToLoadKey($placeKey, $sqlKey);
			$aliases[] = $sqlKey;
		}

		if ($branch->hasNode()) {
			$branch->getPublicNode()->setValueAliases($aliases);
		}
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

	public function registerBranch(RelationLoadBranch $branch): AbstractNode
	{
		$previousMethod = $this->activeMethod;
		$previousRegistering = $this->registering;
		$this->activeMethod = 'register';
		$this->registering = true;

		try {
			$node = $branch->getLoader()->register($branch, $this);
		} finally {
			$this->registering = $previousRegistering;
			$this->activeMethod = $previousMethod;
		}

		if (! $node instanceof AbstractNode) {
			throw LoadRuntimeException::nodeNotRegistered($branch->getRelationRef());
		}

		$branch->setNode($node);

		return $node;
	}

	private function invokeLoaderMethod(RelationLoadBranch $branch, string $method, SelectQuery $query): void
	{
		$loader = $branch->getLoader();
		$previousMethod = $this->activeMethod;
		$previousContinuationRequested = $this->continuationRequested;
		$previousCurrentContinuationQuery = $this->currentContinuationQuery;
		$this->activeMethod = $method;
		$this->continuationRequested = false;
		$this->currentContinuationQuery = $query;
		$this->loaderInvocationDepth++;
		$branch->clearContinuation();

		try {
			$loader->{$method}($branch, $this);
		} finally {
			$this->loaderInvocationDepth--;
			$this->activeMethod = $previousMethod;
			$this->continuationRequested = $previousContinuationRequested;
			$this->currentContinuationQuery = $previousCurrentContinuationQuery;
		}

		if ($this->loaderInvocationDepth === 0) {
			$this->runPendingContinuations();
		}
	}

	private function runContinuationsFor(SelectQuery $query): void
	{
		do {
			$ran = false;

			foreach ($this->branches as $branch) {
				if ($branch->getContinuationQuery() !== $query) {
					continue;
				}

				$method = $branch->getContinuationMethod();

				if ($method === null) {
					continue;
				}

				$branch->clearContinuation();
				$this->invokeLoaderMethod($branch, $method, $query);
				$ran = true;
			}
		} while ($ran);
	}

	private function runPendingContinuations(): void
	{
		if ($this->flushingPendingContinuations) {
			return;
		}

		$this->flushingPendingContinuations = true;

		try {
			while ($this->pendingContinuationQueries !== []) {
				$key = array_key_first($this->pendingContinuationQueries);

				if ($key === null) {
					break;
				}

				$query = $this->pendingContinuationQueries[$key];
				unset($this->pendingContinuationQueries[$key]);
				$this->runContinuationsFor($query);
			}
		} finally {
			$this->flushingPendingContinuations = false;
		}
	}

	private function columnFieldRef(SelectionItem $selection): ?FieldRef
	{
		$expression = $selection->getExpression();

		if ($expression instanceof AliasedExpression) {
			$expression = $expression->getExpression();
		}

		return $expression instanceof FieldRef ? $expression : null;
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
	 * @param list<string> $path
	 */
	private function branchKey(array $path): string
	{
		return json_encode($path, JSON_THROW_ON_ERROR);
	}

	/**
	 * @param list<string> $path
	 */
	private function ensureLevelFieldSelection(
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
					true,
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
			: $this->stableJoinedAlias($path, $fieldName);

		if (! $query->getSelections()->hasNamedExpression($alias)) {
			$query->select($source->field($fieldName)->as($alias));
		}

		return $alias;
	}

	/**
	 * Cycle-style stable column alias for columns mounted onto a shared JOIN query.
	 * Unique per relation path + field; callers reuse the same name on re-require.
	 *
	 * @param list<string> $path
	 */
	private function stableJoinedAlias(array $path, string $fieldName): string
	{
		$slug = strtolower(
			preg_replace('/[^a-z0-9_]+/i', '_', implode('_', [...$path, $fieldName])) ?? 'field',
		);

		return 'l_' . $slug;
	}

	private function assertContinuableMethod(RelationLoadBranch $branch, string $method): void
	{
		$loader = $branch->getLoader();

		if ($method === 'register' || $method === 'join') {
			throw LoadRuntimeException::invalidContinuationMethod($branch->getRelationRef(), $method);
		}

		if (! method_exists($loader, $method)) {
			throw LoadRuntimeException::invalidContinuationMethod($branch->getRelationRef(), $method);
		}

		$reflection = new ReflectionMethod($loader, $method);

		if (! $reflection->isPublic() || $reflection->getNumberOfParameters() !== 2) {
			throw LoadRuntimeException::invalidContinuationMethod($branch->getRelationRef(), $method);
		}
	}
}
