<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation;

use ON\Data\Database\QueryExecutorInterface;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Query\Exception\LoadRuntimeException;
use ON\Data\Query\QuerySourceInterface;
use ON\Data\Query\Result\Parser\AbstractNode;
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

	private int $aliasCounter = 0;

	private RootLoadBranch $rootBranch;

	private RelationOutputProcessor $outputProcessor;

	public function __construct(
		private readonly SelectQuery $rootQuery,
		private readonly QueryExecutorInterface $executor,
	) {
		$this->rootBranch = new RootLoadBranch(
			$rootQuery,
			fn (string $fieldName): string => $this->allocateAlias(['root', 'required'], $fieldName),
		);
		$this->outputProcessor = new RelationOutputProcessor();
	}

	public function fetchAll(): array
	{
		$this->prepare();
		$this->rootBranch->parseRows($this->executor->fetchAll($this->rootQuery));
		$this->runContinuationsFor($this->rootQuery);

		return $this->outputProcessor->processRoot($this->rootBranch);
	}

	public function fetchOne(): ?array
	{
		$this->prepare();
		$row = $this->executor->fetchOne($this->rootQuery);

		if ($row === null) {
			return null;
		}

		$this->rootBranch->parseRows([$row]);
		$this->runContinuationsFor($this->rootQuery);

		return $this->outputProcessor->processRoot($this->rootBranch)[0] ?? null;
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
			$branch->getNode()->parseRow(0, $this->orderedValues($row, $this->branchAliasTraversal($branch)));
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

	private function prepare(): void
	{
		if ($this->rootBranch->hasNode()) {
			return;
		}

		$this->prepareRootBranch();
		$this->createBranches();
		$this->configureBranches();
		$this->createParserTree();
		$this->selectBranchFields();
	}

	private function prepareRootBranch(): void
	{
		$this->rootBranch->registerPublicSelections();
		$this->rootBranch->requirePrimaryKey();
	}

	private function createBranches(): void
	{
		foreach ($this->rootQuery->getRelationSelections()->getAll() as $selection) {
			$key = $this->branchKey($selection->getPath());
			$parent = $selection->getParentPathKey() === null
				? $this->rootBranch
				: $this->branches[$selection->getParentPathKey()] ?? throw LoadRuntimeException::parentBranchMissing($selection->getRelationRef());

			$this->branches[$key] = new RelationLoadBranch($selection, $parent, $selection->getRelationRef()->getLoader(), $this->publicFieldsForSelection($selection));
		}
	}

	private function configureBranches(): void
	{
		$branches = array_values($this->branches);
		usort($branches, static fn (RelationLoadBranch $left, RelationLoadBranch $right): int => count($left->getRelationRef()->getPath()) <=> count($right->getRelationRef()->getPath()));

		foreach ($branches as $branch) {
			$query = $branch->getParent()->getQuery();
			$this->invokeLoaderMethod($branch, 'load', $query);

			if ($branch->getQuery()->getCollection()->getName() === '') {
				throw LoadRuntimeException::queryNotConfigured($branch->getRelationRef());
			}
		}
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

	private function selectBranchFields(): void
	{
		$branches = array_values($this->branches);
		usort($branches, static fn (RelationLoadBranch $left, RelationLoadBranch $right): int => count($left->getRelationRef()->getPath()) <=> count($right->getRelationRef()->getPath()));

		foreach ($branches as $branch) {
			$aliases = [];

			foreach ($branch->getSelections()->getParserItems() as $selection) {
				$aliases[] = $this->ensureBranchFieldSelection(
					$branch->getQuery(),
					$branch->getSource(),
					$branch->getRelationRef()->getPath(),
					$selection->getSelectionKey(),
				);
			}

			$branch->getPublicNode()->setValueAliases($aliases);
		}
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

				if (! $query instanceof SelectQuery) {
					continue;
				}

				$this->runContinuationsFor($query);
			}
		} finally {
			$this->flushingPendingContinuations = false;
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
	 * @return list<string>
	 */
	private function publicFieldsForSelection(RelationSelection $selection): array
	{
		if (! $selection->isLoaded()) {
			return [];
		}

		if ($selection->getFields() !== null) {
			return $selection->getFields();
		}

		return $selection->getRelationRef()->getCollection()->getVisibleFields();
	}

	/**
	 * @param list<string> $path
	 */
	private function branchKey(array $path): string
	{
		return json_encode($path, JSON_THROW_ON_ERROR);
	}

	private function ensureBranchFieldSelection(
		SelectQuery $query,
		QuerySourceInterface $source,
		array $path,
		string $fieldName,
	): string {
		if ($source === $query) {
			$query->select($query->field($fieldName));

			return $fieldName;
		}

		$alias = $this->allocateAlias($path, $fieldName);

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

	/**
	 * @return list<string>
	 */
	private function branchAliasTraversal(RelationLoadBranch $branch): array
	{
		return $branch->getNode()->getValueAliasTraversal();
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
