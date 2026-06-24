<?php

declare(strict_types=1);

namespace ON\Data\Query;

use InvalidArgumentException;
use ON\Data\Definition\DefinitionInterface;
use ON\Data\Definition\Field\FieldInterface;
use ON\Data\Query\Condition\ConditionInterface;
use ON\Data\Query\Exception\UnknownQueryFieldException;
use ON\Data\Query\Expression\AliasedExpression;
use ON\Data\Query\Expression\FieldRef;
use ON\Data\Query\Expression\StarExpression;
use ON\Data\Query\Expression\SubqueryExpression;
use ON\Data\Query\Expression\ValueExpressionInterface;

final class SelectQuery
{
	/**
	 * @var array<string, FieldRef>
	 */
	private array $fieldRefs = [];

	private ?StarExpression $star = null;

	/**
	 * @var list<ValueExpressionInterface|AliasedExpression>
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

	public function star(): StarExpression
	{
		return $this->star ??= new StarExpression($this);
	}

	public function as(string $alias): AliasedExpression
	{
		return (new SubqueryExpression($this))->as($alias);
	}

	public function select(ValueExpressionInterface|AliasedExpression|SelectQuery ...$expressions): self
	{
		if ($expressions === []) {
			throw new InvalidArgumentException('SelectQuery::select() requires at least one expression.');
		}

		array_push($this->selections, ...array_map(
			static fn (ValueExpressionInterface|AliasedExpression|SelectQuery $expression): ValueExpressionInterface|AliasedExpression => $expression instanceof SelectQuery
				? new SubqueryExpression($expression)
				: $expression,
			$expressions,
		));

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
	 * @return list<ValueExpressionInterface|AliasedExpression>
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
