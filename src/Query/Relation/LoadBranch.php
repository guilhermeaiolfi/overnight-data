<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation;

use LogicException;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Query\QuerySourceInterface;
use ON\Data\Query\Result\Parser\AbstractNode;
use ON\Data\Query\Selection\SelectionList;
use ON\Data\Query\SelectQuery;

abstract class LoadBranch
{
	private ?AbstractNode $node = null;

	private ?SelectQuery $query = null;

	private ?QuerySourceInterface $source = null;

	private ?RelationRef $queryLocalRelation = null;

	private ?AbstractNode $publicNode = null;

	private ?string $publicPayloadChild = null;

	/**
	 * @var list<RelationLoadBranch>
	 */
	private array $children = [];

	/**
	 * Place path / selection key → parser/load record key (proposal 0003 Phase 3).
	 *
	 * @var array<string, string>
	 */
	private array $placeToLoadKeys = [];

	public function bindPlaceToLoadKey(string $placeKey, string $loadKey): void
	{
		$this->placeToLoadKeys[$placeKey] = $loadKey;
	}

	public function loadKeyForPlace(string $placeKey): string
	{
		return $this->placeToLoadKeys[$placeKey] ?? $placeKey;
	}

	public function setNode(AbstractNode $node): void
	{
		$this->node = $node;
	}

	public function getNode(): AbstractNode
	{
		return $this->node ?? throw new LogicException('Load branch parser node is not registered.');
	}

	public function hasNode(): bool
	{
		return $this->node !== null;
	}

	abstract public function getCollection(): CollectionInterface;

	/**
	 * Projection level this branch places onto: root {@see SelectQuery} or nested {@see RelationRef}.
	 */
	abstract public function getProjectionLevel(): SelectQuery|RelationRef;

	abstract public function getSelections(): SelectionList;

	/**
	 * @param list<string> $fieldNames
	 * @return list<string>
	 */
	abstract public function requireFields(array $fieldNames): array;

	/**
	 * @return non-empty-list<string>
	 */
	public function requirePrimaryKey(): array
	{
		return $this->requireFields($this->getCollection()->getPrimaryKey());
	}

	public function setPublicNode(AbstractNode $node): void
	{
		$this->publicNode = $node;
	}

	public function getPublicNode(): AbstractNode
	{
		return $this->publicNode ?? $this->getNode();
	}

	public function setPublicPayloadChild(?string $container): void
	{
		$this->publicPayloadChild = $container;
	}

	public function getPublicPayloadChild(): ?string
	{
		return $this->publicPayloadChild;
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

	/**
	 * @return list<RelationLoadBranch>
	 */
	public function getChildren(): array
	{
		return $this->children;
	}

	protected function addChild(RelationLoadBranch $child): void
	{
		$this->children[] = $child;
	}
}
