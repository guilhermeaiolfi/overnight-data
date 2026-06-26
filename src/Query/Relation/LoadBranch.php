<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation;

use LogicException;
use ON\Data\Query\QuerySourceInterface;
use ON\Data\Query\Relation\Loader\LoaderInterface;
use ON\Data\Query\Result\Parser\AbstractNode;
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
	 * @var list<string>
	 */
	private array $fieldOrder = [];

	/**
	 * @var list<string>
	 */
	private array $valueAliases = [];

	private ?string $scheduledMethod = null;

	private ?SelectQuery $scheduledBoundaryQuery = null;

	private ?bool $joinedAttachment = null;

	/**
	 * @param list<string> $publicFields
	 */
	public function __construct(
		private readonly RelationSelection $selection,
		private readonly ?self $parent,
		private readonly LoaderInterface $loader,
		array $publicFields,
	) {
		$this->addFields($publicFields);
	}

	public function getSelection(): RelationSelection
	{
		return $this->selection;
	}

	public function getRelation(): RelationRef
	{
		return $this->selection->getRelation();
	}

	public function getParent(): ?self
	{
		return $this->parent;
	}

	public function getLoader(): LoaderInterface
	{
		return $this->loader;
	}

	public function addFields(array $fieldNames): array
	{
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

	/**
	 * @return list<string>
	 */
	public function getNodeColumns(): array
	{
		return $this->fieldOrder;
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
	 * @return list<string>
	 */
	public function getNodeValueAliases(): array
	{
		return $this->valueAliases;
	}

	/**
	 * @param list<string> $aliases
	 */
	public function setNodeValueAliases(array $aliases): void
	{
		$this->valueAliases = $aliases;
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
}
