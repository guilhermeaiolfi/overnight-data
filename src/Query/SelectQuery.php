<?php

declare(strict_types=1);

namespace ON\Data\Query;

use InvalidArgumentException;
use ON\Data\Definition\DefinitionInterface;
use ON\Data\Definition\Field\FieldInterface;
use ON\Data\Query\Condition\ConditionInterface;
use ON\Data\Query\Exception\UnknownQueryFieldException;
use ON\Data\Query\Expression\FieldRef;
use ON\Data\Query\Expression\ValueExpressionInterface;

final class SelectQuery
{
	/**
	 * @var array<string, FieldRef>
	 */
	private array $fieldRefs = [];

	/**
	 * @var list<ValueExpressionInterface>
	 */
	private array $selections = [];

	/**
	 * @var list<ConditionInterface>
	 */
	private array $conditions = [];

	public function __construct(
		private readonly DefinitionInterface $source,
	) {
	}

	public function getSource(): DefinitionInterface
	{
		return $this->source;
	}

	public function field(string $name): FieldRef
	{
		if (isset($this->fieldRefs[$name])) {
			return $this->fieldRefs[$name];
		}

		$field = $this->source->getField($name);

		if (! $field instanceof FieldInterface) {
			throw UnknownQueryFieldException::forDefinition($name, $this->source->getName());
		}

		return $this->fieldRefs[$name] = new FieldRef($this, $field);
	}

	public function __get(string $name): FieldRef
	{
		return $this->field($name);
	}

	public function select(ValueExpressionInterface ...$expressions): self
	{
		if ($expressions === []) {
			throw new InvalidArgumentException('SelectQuery::select() requires at least one expression.');
		}

		array_push($this->selections, ...$expressions);

		return $this;
	}

	public function where(ConditionInterface ...$conditions): self
	{
		if ($conditions === []) {
			throw new InvalidArgumentException('SelectQuery::where() requires at least one condition.');
		}

		array_push($this->conditions, ...$conditions);

		return $this;
	}

	/**
	 * @return list<ValueExpressionInterface>
	 */
	public function getSelections(): array
	{
		return $this->selections;
	}

	/**
	 * @return list<ConditionInterface>
	 */
	public function getConditions(): array
	{
		return $this->conditions;
	}
}
