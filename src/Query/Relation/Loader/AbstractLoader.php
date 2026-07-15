<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation\Loader;

use LogicException;
use ON\Data\Definition\Relation\RelationKeyPairing;
use ON\Data\Query\Condition\ConditionTag;
use ON\Data\Query\Exception\LoadRuntimeException;
use ON\Data\Query\Exception\RelationLoaderException;
use ON\Data\Query\JoinType;
use ON\Data\Query\QuerySourceInterface;
use ON\Data\Query\Relation\LoadRuntime;
use ON\Data\Query\Relation\LoadStrategy;
use ON\Data\Query\Relation\RelationKeyQuery;
use ON\Data\Query\Relation\RelationLoadBranch;
use ON\Data\Query\Relation\RelationRef;
use ON\Data\Query\Result\Parser\AbstractNode;
use ON\Data\Query\Selection\SelectionItem;
use ON\Data\Query\Selection\SelectionTag;
use ON\Data\Query\SelectQuery;

abstract class AbstractLoader implements LoaderInterface
{
	/**
	 * Max parent-key references per separate-query continuation (Doctrine-style IN batching).
	 */
	protected const SEPARATE_QUERY_BATCH_SIZE = 100;

	public function join(RelationRef $relation): QuerySourceInterface
	{
		$this->assertSupportedRelationPath($relation);
		$this->assertSupportedRelationConstraints($relation);

		$definition = $relation->getDefinition();
		$source = $relation->getParentSource();

		try {
			$keyPairing = $definition->getKeyPairing();
		} catch (LogicException) {
			throw RelationLoaderException::relationKeysIncomplete($relation);
		}

		$join = $relation->getQuery()->join(
			$definition->getCollection(),
			$definition->isNullable() ? JoinType::LEFT : JoinType::INNER,
			implode('.', $relation->getPath()),
			$source,
		);

		RelationKeyQuery::addJoinConditions($keyPairing, $join, $source);

		return $join;
	}

	public function load(RelationLoadBranch $branch, LoadRuntime $runtime): void
	{
		throw RelationLoaderException::loadingNotImplemented($branch->getRelationRef());
	}

	final public function register(RelationLoadBranch $branch, LoadRuntime $runtime): AbstractNode
	{
		foreach ($branch->getChildren() as $child) {
			$runtime->registerBranch($child);
		}

		$node = $this->initNode($branch, $runtime);
		$attachmentNode = $node->getRelationAttachmentNode();

		foreach ($branch->getChildren() as $child) {
			if (! $child->hasNode()) {
				throw LoadRuntimeException::nodeNotRegistered($child->getRelationRef());
			}

			$childNode = $child->getNode();

			if ($child->isJoinedAttachment()) {
				$attachmentNode->joinNode($child->getRelationRef()->getName(), $childNode);

				continue;
			}

			$attachmentNode->linkNode($child->getRelationRef()->getName(), $childNode);
		}

		return $node;
	}

	abstract protected function initNode(RelationLoadBranch $branch, LoadRuntime $runtime): AbstractNode;

	protected function assertSupportedRelationPath(RelationRef $relation): void
	{
	}

	public function getDefaultLoadStrategy(): LoadStrategy
	{
		return LoadStrategy::SEPARATE_QUERY;
	}

	protected function assertSupportedRelationConstraints(RelationRef $relation): void
	{
		$definition = $relation->getDefinition();

		if ($definition->getWhere() !== []) {
			throw RelationLoaderException::relationWhereNotSupported($relation);
		}

		if ($definition->getOrderBy() !== []) {
			throw RelationLoaderException::relationOrderByNotSupported($relation);
		}
	}

	protected function assertNoJoinedSelectionOptions(RelationLoadBranch $branch): void
	{
		$selection = $branch->getSelection();
		$relation = $branch->getRelationRef();

		if ($selection->getConditions() !== []) {
			throw RelationLoaderException::relationWhereNotSupported($relation);
		}

		if ($selection->getSorts() !== []) {
			throw RelationLoaderException::relationOrderByNotSupported($relation);
		}
	}

	protected function applySeparateQueryOptions(RelationLoadBranch $branch): void
	{
		$query = $branch->getQuery();
		$selection = $branch->getSelection();
		$from = $branch->getRelationRef();

		$conditions = $selection->getConditions();

		if ($conditions !== []) {
			$query->bindConditions($from, ...$conditions);
		}

		$sorts = $selection->getSorts();

		if ($sorts !== []) {
			$query->bindSorts($from, ...$sorts);
		}
	}

	/**
	 * Run a separate-query continuation in parent-key chunks (default 100), like Doctrine eager batching.
	 *
	 * Callers should apply template options (where/orderBy) on {@see RelationLoadBranch::getQuery()}
	 * before calling. Each chunk replaces {@see ConditionTag::CORRELATION} on the condition list.
	 *
	 * @param (callable(SelectQuery): QuerySourceInterface)|null $rightSource
	 *        Resolves the correlated source (defaults to the branch query itself).
	 * @param (callable(SelectQuery): SelectQuery)|null $finalize
	 *        Optional transform after correlation (e.g. windowed HasMany / FirstOfMany wrappers).
	 */
	protected function executeSeparateByReferences(
		RelationLoadBranch $branch,
		LoadRuntime $runtime,
		RelationKeyPairing $pairing,
		?callable $rightSource = null,
		?callable $finalize = null,
	): void {
		$references = $branch->getReferenceValues();

		if ($references === []) {
			return;
		}

		$query = $branch->getQuery();
		$batchSize = max(1, $this->separateQueryBatchSize());

		foreach (array_chunk($references, $batchSize) as $chunk) {
			$source = $rightSource !== null ? $rightSource($query) : $query;
			RelationKeyQuery::filterRightByLeftReferences($pairing, $query, $source, $chunk);
			$runtime->execute($branch, $finalize !== null ? $finalize($query) : $query);
		}
	}

	protected function separateQueryBatchSize(): int
	{
		return self::SEPARATE_QUERY_BATCH_SIZE;
	}

	/**
	 * @return list<string>
	 */
	protected function columnSelectionKeys(RelationLoadBranch $branch): array
	{
		return array_map(
			static fn (SelectionItem $selection): string => $selection->getSelectionKey(),
			$branch->getSelections()->getByTag(SelectionTag::COLUMN),
		);
	}
}
