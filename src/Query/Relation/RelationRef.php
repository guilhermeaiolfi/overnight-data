<?php

declare(strict_types=1);

namespace ON\Data\Query\Relation;

use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Field\FieldInterface;
use ON\Data\Definition\Relation\RelationInterface;
use ON\Data\Query\Exception\RelationLoaderException;
use ON\Data\Query\Exception\UnknownQueryFieldException;
use ON\Data\Query\Exception\UnknownQueryMemberException;
use ON\Data\Query\Exception\UnknownQueryRelationException;
use ON\Data\Query\Expression\FieldRef;
use ON\Data\Query\QuerySourceInterface;
use ON\Data\Query\Relation\Loader\LoaderInterface;
use ON\Data\Query\SelectQuery;

final class RelationRef implements QuerySourceInterface
{
	/**
	 * @var array<string, FieldRef>
	 */
	private array $fieldRefs = [];

	/**
	 * @var array<string, self>
	 */
	private array $relationRefs = [];

	private ?LoaderInterface $loader = null;

	private ?QuerySourceInterface $joinedSource = null;

	public function __construct(
		private readonly SelectQuery $query,
		private readonly RelationInterface $relation,
		private readonly ?self $parentRelation = null,
	) {
	}

	public function getQuery(): SelectQuery
	{
		return $this->query;
	}

	public function getRelation(): RelationInterface
	{
		return $this->relation;
	}

	public function getParentRelation(): ?self
	{
		return $this->parentRelation;
	}

	public function getParentSource(): QuerySourceInterface
	{
		return $this->parentRelation?->getJoinedSource()
			?? $this->query;
	}

	public function getJoinedSource(): QuerySourceInterface
	{
		return $this->joinedSource ??= $this->getLoader()->join($this);
	}

	public function getCollection(): CollectionInterface
	{
		return $this->relation->getCollection();
	}

	public function getName(): string
	{
		return $this->relation->getName();
	}

	/**
	 * @return list<string>
	 */
	public function getPath(): array
	{
		$path = $this->parentRelation?->getPath() ?? [];
		$path[] = $this->getName();

		return $path;
	}

	public function field(string $name): FieldRef
	{
		if (isset($this->fieldRefs[$name])) {
			return $this->fieldRefs[$name];
		}

		$field = $this->getCollection()->getField($name);

		if (! $field instanceof FieldInterface) {
			throw UnknownQueryFieldException::forDefinition($name, $this->getCollection()->getName());
		}

		return $this->fieldRefs[$name] = new FieldRef($this, $field);
	}

	public function relation(string $name): self
	{
		if (isset($this->relationRefs[$name])) {
			return $this->relationRefs[$name];
		}

		$relation = $this->getCollection()->getRelation($name);

		if (! $relation instanceof RelationInterface) {
			throw UnknownQueryRelationException::forDefinition($name, $this->getCollection()->getName());
		}

		return $this->relationRefs[$name] = new self($this->query, $relation, $this);
	}

	public function __get(string $name): FieldRef|self
	{
		$collection = $this->getCollection();

		if ($collection->hasField($name)) {
			return $this->field($name);
		}

		if ($collection->hasRelation($name)) {
			return $this->relation($name);
		}

		throw UnknownQueryMemberException::forDefinition($name, $collection->getName());
	}

	public function getLoader(): LoaderInterface
	{
		if ($this->loader !== null) {
			return $this->loader;
		}

		$loader = $this->relation->getLoader();

		if (! is_a($loader, LoaderInterface::class, true)) {
			throw RelationLoaderException::invalidLoader($this, $loader);
		}

		return $this->loader = new $loader();
	}
}
