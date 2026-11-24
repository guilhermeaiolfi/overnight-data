<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation;

use LogicException;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Query\Exception\LoadRuntimeException;
use ON\Data\Query\QuerySourceInterface;
use ON\Data\Query\Result\Parser\AbstractNode;
use ON\Data\Query\SelectQuery;
use ON\Data\Schema\RepresentationSchema;

abstract class LoadBranch
{
	private ?AbstractNode $node = null;

	private ?SelectQuery $query = null;

	private ?QuerySourceInterface $source = null;

	private ?RelationRef $queryLocalRelation = null;

	private ?string $publicPayloadChild = null;

	/**
	 * @var list<RelationLoadBranch>
	 */
	private array $children = [];

	/**
	 * @var array<string, true>
	 */
	private array $requiredFieldNames = [];

	private ?RepresentationSchema $representationSchema = null;

	private ?ColumnIndex $columnIndex = null;

	/**
	 * Bind this branch's schema node for the current fetch.
	 */
	public function setRepresentationSchema(RepresentationSchema $schema): void
	{
		$this->representationSchema = $schema;
	}

	public function getRepresentationSchema(): RepresentationSchema
	{
		return $this->representationSchema ?? throw LoadRuntimeException::schemaMissing();
	}

	public function setColumnIndex(ColumnIndex $columnIndex): void
	{
		$this->columnIndex = $columnIndex;
	}

	public function getColumnIndex(): ColumnIndex
	{
		return $this->columnIndex ?? throw new LogicException('Load branch column index is not configured.');
	}

	/**
	 * Local parser remap: bag name => SQL alias (`c0`, `c1`, ...).
	 *
	 * @return array<string, string>
	 */
	public function getRemapColumns(): array
	{
		return $this->getColumnIndex()->remapColumnsFor($this);
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
	 * Projection level this branch writes onto: root {@see SelectQuery} or nested {@see RelationRef}.
	 */
	abstract public function getProjectionLevel(): SelectQuery|RelationRef;

	/**
	 * Record own-level fields required for identity or join correlation.
	 * {@see ColumnIndex::planRequiredFields()} allocates SQL columns after schema planning.
	 *
	 * @param list<string> $fieldNames
	 * @return list<string>
	 */
	public function requireFields(array $fieldNames): array
	{
		if ($fieldNames === []) {
			return [];
		}

		$added = [];

		foreach ($fieldNames as $fieldName) {
			$normalized = $this->getCollection()->getField($fieldName)->getName();
			$this->requiredFieldNames[$normalized] = true;
			$added[] = $normalized;
		}

		return $added;
	}

	/**
	 * @return list<string>
	 */
	public function getRequiredFieldNames(): array
	{
		return array_keys($this->requiredFieldNames);
	}

	/**
	 * @return non-empty-list<string>
	 */
	public function requirePrimaryKey(): array
	{
		/** @var non-empty-list<string> */
		return $this->requireFields($this->getCollection()->getPrimaryKey());
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

	public function hasQuery(): bool
	{
		return $this->query !== null;
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
