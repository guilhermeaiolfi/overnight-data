<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation\Loader;

use LogicException;
use ON\Data\Query\Exception\LoadRuntimeException;
use ON\Data\Query\Exception\RelationLoaderException;
use ON\Data\Query\Join;
use ON\Data\Query\JoinType;
use ON\Data\Query\QuerySourceInterface;
use ON\Data\Query\Relation\LoadRuntime;
use ON\Data\Query\Relation\LoadStrategy;
use ON\Data\Query\Relation\RelationRef;
use ON\Data\Query\Result\Parser\AbstractNode;
use function ON\Data\Query\x;

abstract class AbstractLoader implements LoaderInterface
{
	public function join(RelationRef $relation): QuerySourceInterface
	{
		$this->assertSupportedRelationPath($relation);
		$this->assertSupportedRelationConstraints($relation);

		$definition = $relation->getRelation();
		$source = $relation->getParentSource();
		$innerKeys = $this->relationKeys($relation, 'inner');
		$outerKeys = $this->relationKeys($relation, 'outer');
		$join = $relation->getQuery()->join(
			$definition->getCollection(),
			$definition->isNullable() ? JoinType::LEFT : JoinType::INNER,
			implode('.', $relation->getPath()),
			$source,
		);

		$this->addKeyConditions(
			$join,
			$source,
			$innerKeys,
			$outerKeys,
		);

		return $join;
	}

	public function load(RelationRef $relation, LoadRuntime $runtime): void
	{
		throw RelationLoaderException::loadingNotImplemented($relation);
	}

	final public function register(RelationRef $relation, LoadRuntime $runtime): AbstractNode
	{
		$runtime->registerChildBranches();
		$node = $this->initNode($relation, $runtime);
		$attachmentNode = $node->getRelationAttachmentNode();

		foreach ($runtime->getChildBranches() as $child) {
			if (! $child->hasNode()) {
				throw LoadRuntimeException::nodeNotRegistered($child->getRelation());
			}

			$childNode = $child->getNode();

			if ($child->isJoinedAttachment()) {
				$attachmentNode->joinNode($child->getRelation()->getName(), $childNode);

				continue;
			}

			$attachmentNode->linkNode($child->getRelation()->getName(), $childNode);
		}

		return $node;
	}

	abstract protected function initNode(RelationRef $relation, LoadRuntime $runtime): AbstractNode;

	protected function assertSupportedRelationPath(RelationRef $relation): void
	{
	}

	public function getDefaultLoadStrategy(): LoadStrategy
	{
		return LoadStrategy::SEPARATE_QUERY;
	}

	protected function assertSupportedRelationConstraints(RelationRef $relation): void
	{
		$definition = $relation->getRelation();

		if ($definition->getWhere() !== []) {
			throw RelationLoaderException::relationWhereNotSupported($relation);
		}

		if ($definition->getOrderBy() !== []) {
			throw RelationLoaderException::relationOrderByNotSupported($relation);
		}
	}

	/**
	 * @param non-empty-list<string> $sourceKeys
	 * @param non-empty-list<string> $targetKeys
	 */
	protected function addKeyConditions(
		Join $join,
		QuerySourceInterface $source,
		array $sourceKeys,
		array $targetKeys,
	): void {
		foreach ($sourceKeys as $index => $sourceKey) {
			$join->on(
				x()->eq($source->field($sourceKey), $join->field($targetKeys[$index])),
			);
		}
	}

	/**
	 * @return non-empty-list<string>
	 */
	protected function relationKeys(RelationRef $relation, string $side): array
	{
		try {
			$keys = $side === 'inner'
				? $relation->getRelation()->innerKeys()
				: $relation->getRelation()->outerKeys();
		} catch (LogicException) {
			throw RelationLoaderException::relationKeysIncomplete($relation);
		}

		if ($keys === []) {
			throw RelationLoaderException::relationKeysIncomplete($relation);
		}

		return $keys;
	}
}
