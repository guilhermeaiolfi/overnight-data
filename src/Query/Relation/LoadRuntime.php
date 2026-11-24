<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation;

use Closure;
use ON\Data\Database\Exception\UnsupportedQueryException;
use ON\Data\Database\QueryExecutorInterface;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Query\Exception\LoadRuntimeException;
use ON\Data\Query\Exception\RelationSelectionException;
use ON\Data\Query\Expression\AliasedExpression;
use ON\Data\Query\Expression\FieldRef;
use ON\Data\Query\Expression\SourceFieldExpression;
use ON\Data\Query\Expression\StarExpression;
use ON\Data\Query\Expression\ValueExpressionInterface;
use ON\Data\Query\QuerySourceInterface;
use ON\Data\Query\Result\Parser\AbstractNode;
use ON\Data\Query\SelectQuery;
use ON\Data\Schema\RepresentationSchema;

final class LoadRuntime
{
	/**
	 * @var array<string, RelationLoadBranch>
	 */
	private array $branches = [];

	/** @var array<int, Closure(RelationLoadBranch, LoadRuntime): void> */
	private array $continuations = [];

	private RootLoadBranch $rootBranch;

	private RelationOutputProcessor $outputProcessor;

	private ColumnIndex $columns;

	/**
	 * Same-level identity overlays from the last assemble, or null on the fast path.
	 *
	 * @var list<array<string, mixed>>|null
	 */
	private ?array $identityRows = null;

	public function __construct(
		private readonly SelectQuery $rootQuery,
		private readonly QueryExecutorInterface $executor,
		private readonly ?RepresentationSchema $schema = null,
	) {
		$this->rootBranch = new RootLoadBranch($rootQuery);
		$this->outputProcessor = new RelationOutputProcessor($schema);
		$this->columns = new ColumnIndex();
	}

	public function fetchAll(): array
	{
		$this->identityRows = null;

		if (! $this->rootQuery->needsRowAssemble()) {
			return $this->executor->fetchAll($this->rootQuery);
		}

		$this->prepare();
		$rows = $this->executor->fetchAll($this->rootQuery);

		if ($this->rootBranch->getChildren() === []) {
			return $this->storeAssembledRows(
				$this->outputProcessor->processRawRows($this->rootBranch, $rows),
			);
		}

		$this->rootBranch->parseRows($rows);
		$this->loadChildBranches($this->rootBranch);

		return $this->storeAssembledRows($this->outputProcessor->processRoot($this->rootBranch));
	}

	public function fetchOne(): ?array
	{
		$this->identityRows = null;

		if (! $this->rootQuery->needsRowAssemble()) {
			return $this->executor->fetchOne($this->rootQuery);
		}

		$this->prepare();
		$row = $this->executor->fetchOne($this->rootQuery);

		if ($row === null) {
			return null;
		}

		if ($this->rootBranch->getChildren() === []) {
			$public = $this->storeAssembledRows(
				$this->outputProcessor->processRawRows($this->rootBranch, [$row]),
			);

			return $public[0] ?? null;
		}

		$this->rootBranch->parseRows($this->rowsForFirstRoot($row));
		$this->loadChildBranches($this->rootBranch);

		$public = $this->storeAssembledRows($this->outputProcessor->processRoot($this->rootBranch));

		return $public[0] ?? null;
	}

	/**
	 * A single SQL row does not contain the whole root when a joined branch is to-many.
	 * Select the root using the original pagination, then fetch its complete joined rows.
	 *
	 * @param array<string, mixed> $firstRow
	 * @return list<array<string, mixed>>
	 */
	private function rowsForFirstRoot(array $firstRow): array
	{
		foreach ($this->branches as $branch) {
			if (! $branch->isJoinedAttachment() || ! $branch->returnsMany() || $branch->getQuery() !== $this->rootQuery) {
				continue;
			}

			$query = $this->rootQuery->copy()->limit(null)->offset(null);
			$columns = $this->rootBranch->getRemapColumns();
			foreach ($this->rootBranch->getCollection()->getPrimaryKey() as $fieldName) {
				$query->where($query->field($fieldName)->eq($firstRow[$columns[$fieldName]]));
			}

			return $this->executor->fetchAll($query);
		}

		return [$firstRow];
	}

	/**
	 * Identity overlays from the last {@see fetchAll()} / {@see fetchOne()} assemble.
	 * Null when the fast path skipped assemble (caller may use public rows).
	 *
	 * @return list<array<string, mixed>>|null
	 */
	public function getIdentityRows(): ?array
	{
		return $this->identityRows;
	}

	public function getColumnIndex(): ColumnIndex
	{
		return $this->columns;
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

	public function selectAuxiliaryColumn(
		RelationLoadBranch $branch,
		ValueExpressionInterface $expression,
		ColumnRole $role = ColumnRole::JoinKey,
	): string {
		return $this->columns->allocateAuxiliary($branch->getQuery(), $branch, $expression, $role);
	}

	/**
	 * Register deferred work during loader setup. It runs after the parent rows
	 * are parsed and before this branch's descendants load.
	 *
	 * @param Closure(RelationLoadBranch, LoadRuntime): void $load
	 */
	public function continueWith(RelationLoadBranch $branch, Closure $load): void
	{
		$key = RelationSelection::pathKey($branch->getRelationRef()->getPath());
		if ($this->rootBranch->hasNode() || ($this->branches[$key] ?? null) !== $branch) {
			throw new LoadRuntimeException('Deferred loader work must be registered during branch setup.');
		}

		$id = spl_object_id($branch);
		if (isset($this->continuations[$id])) {
			throw new LoadRuntimeException(sprintf("Deferred loader work already exists for relation '%s'.", $key));
		}

		$this->continuations[$id] = $load;
	}

	public function execute(RelationLoadBranch $branch, SelectQuery $query): void
	{
		$rows = $this->executor->fetchAll($query);

		foreach ($rows as $row) {
			$branch->getNode()->parseRow($row);
		}
	}

	public function getLoadStrategy(RelationLoadBranch $branch): LoadStrategy
	{
		return $branch->getSelection()->getStrategy()
			?? $branch->getLoader()->getDefaultLoadStrategy();
	}

	public function registerBranch(RelationLoadBranch $branch): AbstractNode
	{
		$node = $branch->getLoader()->register($branch, $this);

		if (! $node instanceof AbstractNode) {
			throw LoadRuntimeException::nodeNotRegistered($branch->getRelationRef());
		}

		$branch->setNode($node);

		return $node;
	}

	/**
	 * @param array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>} $assembled
	 * @return list<array<string, mixed>>
	 */
	private function storeAssembledRows(array $assembled): array
	{
		[$public, $identity] = $assembled;
		$this->identityRows = $identity;

		return $public;
	}

	private function prepare(): void
	{
		if ($this->rootBranch->hasNode()) {
			return;
		}

		$schema = $this->schema ?? throw LoadRuntimeException::schemaMissing();

		$this->assertUniqueRootSelectionNames();
		$this->rootBranch->requirePrimaryKey();
		$this->createBranches();
		$this->configureBranches();
		$this->planColumns($schema);
		$this->createParserTree();
		$this->columns->applyToQueries();
	}

	private function createBranches(): void
	{
		foreach ($this->rootQuery->getRelationSelections()->getAll() as $selection) {
			$key = RelationSelection::pathKey($selection->getPath());
			$parent = $selection->getParentPathKey() === null
				? $this->rootBranch
				: $this->branches[$selection->getParentPathKey()] ?? throw LoadRuntimeException::parentBranchMissing($selection->getRelationRef());

			$this->branches[$key] = new RelationLoadBranch($selection, $parent, $selection->getRelationRef()->getLoader());
		}
	}

	private function configureBranches(): void
	{
		foreach ($this->relationBranchesByDepth() as $branch) {
			$branch->getLoader()->load($branch, $this);

			if (! $branch->hasQuery() || $branch->getQuery()->getCollection()->getName() === '') {
				throw LoadRuntimeException::queryNotConfigured($branch->getRelationRef());
			}
		}
	}

	private function planColumns(RepresentationSchema $rootSchema): void
	{
		$this->columns->planBranch($this->rootBranch, $rootSchema);

		$relationBranches = $this->relationBranchesByDepth();

		foreach ($relationBranches as $branch) {
			$path = $branch->getRelationRef()->getPath();
			$nodeSchema = $rootSchema->findRelatedSchemaAt($path);

			if (! $nodeSchema instanceof RepresentationSchema) {
				throw LoadRuntimeException::schemaNodeMissing($path);
			}

			$this->columns->planBranch($branch, $nodeSchema);
		}

		$this->columns->planRequiredFields($this->rootBranch);

		foreach ($relationBranches as $branch) {
			$this->columns->planRequiredFields($branch);
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

	private function loadChildBranches(LoadBranch $parent): void
	{
		foreach ($parent->getChildren() as $child) {
			$load = $this->continuations[spl_object_id($child)] ?? null;
			if ($load !== null) {
				$load($child, $this);
			}

			$this->loadChildBranches($child);
		}
	}

	private function assertUniqueRootSelectionNames(): void
	{
		$usedNames = [];

		foreach ($this->rootQuery->getSelections()->getExplicit() as $selection) {
			$expression = $selection->getExpression();
			$inner = $expression instanceof AliasedExpression
				? $expression->getExpression()
				: $expression;

			if ($inner instanceof StarExpression) {
				$source = $inner->getSource();
				$names = $source instanceof SelectQuery && ! $source->isDerivedSource()
					? $source->getCollection()->getVisibleFields()
					: [];
			} elseif ($expression instanceof AliasedExpression) {
				$names = [$expression->getAlias()];
			} elseif ($inner instanceof FieldRef) {
				$path = $inner->getPath();
				$names = [count($path) === 1 ? $selection->getSelectionKey() : implode('.', $path)];
			} elseif ($inner instanceof SourceFieldExpression) {
				$names = [$inner->getName()];
			} else {
				continue;
			}

			foreach ($names as $name) {
				if (isset($usedNames[$name])) {
					throw UnsupportedQueryException::forQuery($this->rootQuery, sprintf(
						"duplicate root result name '%s' is not supported",
						$name,
					));
				}

				$usedNames[$name] = true;
			}
		}
	}
}
