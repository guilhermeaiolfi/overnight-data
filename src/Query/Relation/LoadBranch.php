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
	 * @param list<string> $publicFields
	 */
	public function __construct(
		private readonly RelationSelection $selection,
		private readonly ?self $parent,
		private readonly LoaderInterface $loader,
		array $publicFields,
	) {
		$this->addPublicFields($publicFields);
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

	public function requireFields(array $fieldNames): array
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
}
