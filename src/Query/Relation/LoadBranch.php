<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation;

use LogicException;
use ON\Data\Query\QuerySourceInterface;
use ON\Data\Query\Relation\Loader\LoaderInterface;
use ON\Data\Query\Result\Parser\AbstractNode;
use ON\Data\Query\Result\Parser\RootNode;
use ON\Data\Query\SelectQuery;

final class LoadBranch
{
	private ?AbstractNode $node = null;

	/**
	 * @param list<string> $nodeColumns
	 * @param list<string> $nodeValueAliases
	 * @param list<string> $nodeIdentityFields
	 * @param non-empty-list<string> $nodeParentFields
	 * @param non-empty-list<string> $nodeChildFields
	 */
	public function __construct(
		private readonly RelationRef $relation,
		private readonly ?self $parent,
		private readonly LoaderInterface $loader,
		private readonly LoadStrategy $strategy,
		private readonly SelectQuery $query,
		private readonly QuerySourceInterface $source,
		private readonly ?RelationRef $queryLocalRelation,
		private readonly array $nodeColumns,
		private readonly array $nodeValueAliases,
		private readonly array $nodeIdentityFields,
		private readonly array $nodeParentFields,
		private readonly array $nodeChildFields,
	) {
	}

	public function getRelation(): RelationRef
	{
		return $this->relation;
	}

	public function getParent(): ?self
	{
		return $this->parent;
	}

	public function getLoader(): LoaderInterface
	{
		return $this->loader;
	}

	public function getStrategy(): LoadStrategy
	{
		return $this->strategy;
	}

	public function getQuery(): SelectQuery
	{
		return $this->query;
	}

	public function getSource(): QuerySourceInterface
	{
		return $this->source;
	}

	public function getQueryLocalRelation(): ?RelationRef
	{
		return $this->queryLocalRelation;
	}

	/**
	 * @return list<string>
	 */
	public function getNodeColumns(): array
	{
		return $this->nodeColumns;
	}

	/**
	 * @return list<string>
	 */
	public function getNodeValueAliases(): array
	{
		return $this->nodeValueAliases;
	}

	/**
	 * @return list<string>
	 */
	public function getNodeIdentityFields(): array
	{
		return $this->nodeIdentityFields;
	}

	/**
	 * @return non-empty-list<string>
	 */
	public function getNodeParentFields(): array
	{
		return $this->nodeParentFields;
	}

	/**
	 * @return non-empty-list<string>
	 */
	public function getNodeChildFields(): array
	{
		return $this->nodeChildFields;
	}

	public function getParentNode(RootNode $rootNode): AbstractNode
	{
		return $this->parent?->getNode() ?? $rootNode;
	}

	public function hasNode(): bool
	{
		return $this->node !== null;
	}

	public function setNode(AbstractNode $node): void
	{
		$this->node = $node;
	}

	public function getNode(): AbstractNode
	{
		return $this->node ?? throw new LogicException('Load branch parser node is not registered.');
	}
}
