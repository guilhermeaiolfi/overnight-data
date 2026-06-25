<?php

declare(strict_types=1);

namespace ON\Data\Query;

use InvalidArgumentException;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Field\FieldInterface;
use ON\Data\Query\Condition\ConditionInterface;
use ON\Data\Query\Exception\UnknownQueryFieldException;
use ON\Data\Query\Exception\UnknownQueryMemberException;
use ON\Data\Query\Expression\FieldRef;

final class Join implements QuerySourceInterface
{
	/**
	 * @var list<ConditionInterface>
	 */
	private array $conditions = [];

	/**
	 * @var array<string, FieldRef>
	 */
	private array $fieldRefs = [];

	public function __construct(
		private readonly SelectQuery $query,
		private readonly QuerySourceInterface $source,
		private readonly CollectionInterface $collection,
		private readonly JoinType $type,
		private readonly string $name,
	) {
	}

	public function on(ConditionInterface ...$conditions): self
	{
		if ($conditions === []) {
			throw new InvalidArgumentException('Join::on() requires at least one condition.');
		}

		array_push($this->conditions, ...$conditions);

		return $this;
	}

	public function getQuery(): SelectQuery
	{
		return $this->query;
	}

	public function getSource(): QuerySourceInterface
	{
		return $this->source;
	}

	public function getCollection(): CollectionInterface
	{
		return $this->collection;
	}

	public function getType(): JoinType
	{
		return $this->type;
	}

	public function getName(): string
	{
		return $this->name;
	}

	public function getPath(): array
	{
		return [$this->name];
	}

	/**
	 * @return list<ConditionInterface>
	 */
	public function getConditions(): array
	{
		return $this->conditions;
	}

	public function field(string $name): FieldRef
	{
		if (isset($this->fieldRefs[$name])) {
			return $this->fieldRefs[$name];
		}

		$field = $this->collection->getField($name);

		if (! $field instanceof FieldInterface) {
			throw UnknownQueryFieldException::forDefinition($name, $this->collection->getName());
		}

		return $this->fieldRefs[$name] = new FieldRef($this, $field);
	}

	public function __get(string $name): FieldRef
	{
		if ($this->collection->hasField($name)) {
			return $this->field($name);
		}

		throw UnknownQueryMemberException::forDefinition($name, $this->collection->getName());
	}
}
