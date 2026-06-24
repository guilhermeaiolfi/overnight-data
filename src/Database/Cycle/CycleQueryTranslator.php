<?php

declare(strict_types=1);

namespace ON\Data\Database\Cycle;

use Cycle\Database\DatabaseInterface;
use Cycle\Database\Injection\FragmentInterface;
use Cycle\Database\Injection\Parameter;
use Cycle\Database\Injection\ParameterInterface;
use Cycle\Database\Query\QueryParameters;
use Cycle\Database\Query\SelectQuery as CycleSelectQuery;
use ON\Data\Database\Exception\UnsupportedQueryException;
use ON\Data\Definition\Collection\Collection;
use ON\Data\Definition\DefinitionInterface;
use ON\Data\Definition\Field\FieldInterface;
use ON\Data\Definition\View\ViewDefinition;
use ON\Data\Mapper\ConversionGateway;
use ON\Data\Mapper\Representation\PhpRepresentation;
use ON\Data\Mapper\Representation\StorageRepresentation;
use ON\Data\Mapper\Resolution\LeafNodeResolution;
use ON\Data\Query\Condition\ComparisonCondition;
use ON\Data\Query\Condition\ComparisonOperator;
use ON\Data\Query\Condition\ConditionInterface;
use ON\Data\Query\Condition\ExistsCondition;
use ON\Data\Query\Condition\InCondition;
use ON\Data\Query\Condition\LogicalCondition;
use ON\Data\Query\Condition\LogicalOperator;
use ON\Data\Query\Condition\NotCondition;
use ON\Data\Query\Condition\NullCondition;
use ON\Data\Query\Condition\NullOperator;
use ON\Data\Query\Expression\AggregateExpression;
use ON\Data\Query\Expression\AggregateFunction;
use ON\Data\Query\Expression\AliasedExpression;
use ON\Data\Query\Expression\FieldRef;
use ON\Data\Query\Expression\LiteralExpression;
use ON\Data\Query\Expression\StarExpression;
use ON\Data\Query\Expression\SubqueryExpression;
use ON\Data\Query\Expression\ValueExpressionInterface;
use ON\Data\Query\Expression\ValueOperation;
use ON\Data\Query\Expression\ValueOperationExpression;
use ON\Data\Query\SelectQuery;
use ON\Data\Query\Sort\SortDirection;

/**
 * @internal
 */
final class CycleQueryTranslator
{
	public function __construct(
		private readonly DatabaseInterface $database,
		private readonly ConversionGateway $gateway,
	) {
	}

	public function translate(SelectQuery $query): CycleQueryPlan
	{
		$context = new CycleTranslationContext($query, $this->database->getDriver()->getQueryCompiler());

		return $context->within($query, function () use ($query, $context): CycleQueryPlan {
			$cycle = $this->database->select();
			$cycle->from($this->fromSource($query, $context)->toCycleFragment());

			[$columns, $resultColumns] = $this->translateSelections($query, $context, true);
			$cycle->columns($columns);

			foreach ($query->getConditions() as $condition) {
				$cycle->where($this->translateCondition($condition, $context)->toCycleFragment());
			}

			foreach ($query->getGroups() as $group) {
				$cycle->groupBy($this->translateExpression($group, $context)->toCycleFragment());
			}

			foreach ($query->getHavingConditions() as $condition) {
				$cycle->having($this->translateCondition($condition, $context)->toCycleFragment());
			}

			foreach ($query->getSorts() as $sort) {
				$cycle->orderBy(
					$this->translateExpression($sort->getExpression(), $context)->toCycleFragment(),
					$sort->getDirection() === SortDirection::ASC ? CycleSelectQuery::SORT_ASC : CycleSelectQuery::SORT_DESC,
				);
			}

			$cycle->limit($query->getLimit());
			$cycle->offset($query->getOffset());

			return new CycleQueryPlan($cycle, $resultColumns);
		});
	}

	/**
	 * @return array{0: list<string|FragmentInterface>, 1: list<CycleResultColumn>}
	 */
	private function translateSelections(SelectQuery $query, CycleTranslationContext $context, bool $root): array
	{
		$selections = $query->getSelections();
		if ($root && $selections === []) {
			throw UnsupportedQueryException::forQuery($query, 'root execution requires at least one selection');
		}

		$columns = [];
		$resultColumns = [];
		$logicalNames = [];

		foreach ($selections as $selection) {
			$aliased = $selection instanceof AliasedExpression;
			$expression = $aliased ? $selection->getExpression() : $selection;
			$logicalName = null;
			$field = $expression instanceof FieldRef ? $expression->getField() : null;

			if ($root) {
				$logicalName = $this->resolveRootResultName($query, $selection, $expression);

				if (isset($logicalNames[$logicalName])) {
					throw UnsupportedQueryException::forQuery($query, sprintf(
						"duplicate root result name '%s' is not supported",
						$logicalName,
					));
				}

				$logicalNames[$logicalName] = true;
			}

			$sql = $this->translateExpression($expression, $context);

			if ($root || $aliased) {
				$alias = $logicalName ?? $selection->getAlias();
				$columns[] = SqlFragment::withParameters(
					$sql->sql() . ' AS ' . $this->quote($alias),
					$sql->parameters(),
				)->toCycleFragment();
			} else {
				$columns[] = $sql->toCycleFragment();
			}

			if ($root && $logicalName !== null) {
				$resultColumns[] = new CycleResultColumn($logicalName, $logicalName, $field);
			}
		}

		return [$columns, $resultColumns];
	}

	private function resolveRootResultName(
		SelectQuery $query,
		ValueExpressionInterface|AliasedExpression $selection,
		ValueExpressionInterface $expression,
	): string {
		if ($selection instanceof AliasedExpression) {
			return $selection->getAlias();
		}

		if ($expression instanceof FieldRef) {
			return $expression->getField()->getName();
		}

		throw UnsupportedQueryException::forQuery(
			$query,
			'unaliased computed, aggregate, and subquery root selections are not supported',
		);
	}

	private function translateCondition(ConditionInterface $condition, CycleTranslationContext $context): SqlFragment
	{
		return match (true) {
			$condition instanceof ComparisonCondition => $this->translateComparison($condition, $context),
			$condition instanceof NullCondition => SqlFragment::raw(sprintf(
				'%s %s',
				$this->translateExpression($condition->getExpression(), $context)->sql(),
				$condition->getOperator() === NullOperator::IS_NULL ? 'IS NULL' : 'IS NOT NULL',
			)),
			$condition instanceof LogicalCondition => $this->translateLogical($condition, $context),
			$condition instanceof NotCondition => $this->wrapSql(
				'NOT (%s)',
				[$this->translateCondition($condition->getCondition(), $context)],
			),
			$condition instanceof InCondition => $this->translateIn($condition, $context),
			$condition instanceof ExistsCondition => $this->translateExists($condition, $context),
			default => throw UnsupportedQueryException::forQuery($context->root(), 'unknown condition type'),
		};
	}

	private function translateComparison(ComparisonCondition $condition, CycleTranslationContext $context): SqlFragment
	{
		$left = $condition->getLeft();
		$right = $condition->getRight();

		$leftSql = $this->translateExpression($left, $context);
		$rightSql = $this->translateExpression(
			$right,
			$context,
			$left instanceof FieldRef && $right instanceof LiteralExpression ? $left->getField() : null,
		);

		return $this->wrapSql(
			'%s %s %s',
			[
				$leftSql,
				SqlFragment::raw(match ($condition->getOperator()) {
					ComparisonOperator::EQ => '=',
					ComparisonOperator::NEQ => '!=',
					ComparisonOperator::GT => '>',
					ComparisonOperator::GTE => '>=',
					ComparisonOperator::LT => '<',
					ComparisonOperator::LTE => '<=',
				}),
				$rightSql,
			],
		);
	}

	private function translateLogical(LogicalCondition $condition, CycleTranslationContext $context): SqlFragment
	{
		$operator = $condition->getOperator() === LogicalOperator::AND ? ' AND ' : ' OR ';
		$parts = array_map(
			fn (ConditionInterface $nested): SqlFragment => $this->translateCondition($nested, $context),
			$condition->getConditions(),
		);

		$sql = '(' . implode(
			$operator,
			array_map(static fn (SqlFragment $fragment): string => $fragment->sql(), $parts),
		) . ')';

		return SqlFragment::withParameters($sql, $this->mergeParameters($parts));
	}

	private function translateIn(InCondition $condition, CycleTranslationContext $context): SqlFragment
	{
		$expression = $this->translateExpression($condition->getExpression(), $context);
		$operator = $condition->isNegated() ? 'NOT IN' : 'IN';

		if ($condition->getSet() instanceof SubqueryExpression) {
			$subquery = $this->compileNestedQuery($condition->getSet()->getQuery(), $context, QueryUsage::IN_SUBQUERY);

			return $this->wrapSql(
				'%s %s %s',
				[$expression, SqlFragment::raw($operator), $subquery],
			);
		}

		$field = $condition->getExpression() instanceof FieldRef ? $condition->getExpression()->getField() : null;
		$items = array_map(
			fn (ValueExpressionInterface $item): SqlFragment => $this->translateExpression(
				$item,
				$context,
				$field !== null && $item instanceof LiteralExpression ? $field : null,
			),
			$condition->getSet(),
		);

		return SqlFragment::withParameters(
			sprintf(
				'%s %s (%s)',
				$expression->sql(),
				$operator,
				implode(', ', array_map(static fn (SqlFragment $fragment): string => $fragment->sql(), $items)),
			),
			array_merge($expression->parameters(), $this->mergeParameters($items)),
		);
	}

	private function translateExists(ExistsCondition $condition, CycleTranslationContext $context): SqlFragment
	{
		$subquery = $this->compileNestedQuery($condition->getQuery(), $context, QueryUsage::EXISTS_SUBQUERY);

		return $this->wrapSql(
			'%s %s',
			[
				SqlFragment::raw($condition->isNegated() ? 'NOT EXISTS' : 'EXISTS'),
				$subquery,
			],
		);
	}

	private function translateExpression(
		ValueExpressionInterface $expression,
		CycleTranslationContext $context,
		?FieldInterface $literalFieldContext = null,
	): SqlFragment {
		return match (true) {
			$expression instanceof FieldRef => $this->translateFieldRef($expression, $context),
			$expression instanceof LiteralExpression => $this->translateLiteral($expression, $literalFieldContext),
			$expression instanceof AggregateExpression => $this->translateAggregate($expression, $context),
			$expression instanceof ValueOperationExpression => $this->translateValueOperation($expression, $context),
			$expression instanceof SubqueryExpression => $this->compileNestedQuery($expression->getQuery(), $context, QueryUsage::SCALAR_SUBQUERY),
			default => throw UnsupportedQueryException::forQuery($context->root(), 'unknown value expression type'),
		};
	}

	private function translateFieldRef(FieldRef $field, CycleTranslationContext $context): SqlFragment
	{
		$context->assertAccessible($field);

		return SqlFragment::raw($this->quote(
			$context->aliasFor($field->getQuery()) . '.' . $field->getField()->getColumn()
		));
	}

	private function translateLiteral(LiteralExpression $literal, ?FieldInterface $fieldContext): SqlFragment
	{
		$value = $literal->getValue();

		if ($fieldContext !== null && $value !== null) {
			$value = $this->gateway->to(
				PhpRepresentation::class,
				$value,
				StorageRepresentation::class,
				LeafNodeResolution::fromField($fieldContext),
			);
		}

		return SqlFragment::withParameters('?', [new Parameter($value)]);
	}

	private function translateAggregate(AggregateExpression $expression, CycleTranslationContext $context): SqlFragment
	{
		if ($expression->getExpression() instanceof StarExpression) {
			return SqlFragment::raw('COUNT(*)');
		}

		$inner = $this->translateExpression($expression->getExpression(), $context);

		$sql = match ($expression->getFunction()) {
			AggregateFunction::COUNT => $expression->getExpression() instanceof StarExpression
				? 'COUNT(*)'
				: 'COUNT(' . $inner->sql() . ')',
			AggregateFunction::COUNT_DISTINCT => 'COUNT(DISTINCT ' . $inner->sql() . ')',
			AggregateFunction::SUM => 'SUM(' . $inner->sql() . ')',
		};

		return SqlFragment::withParameters($sql, $inner->parameters());
	}

	private function translateValueOperation(ValueOperationExpression $expression, CycleTranslationContext $context): SqlFragment
	{
		$arguments = array_map(
			fn (ValueExpressionInterface $argument): SqlFragment => $this->translateExpression($argument, $context),
			$expression->getArguments(),
		);
		$sqlArguments = array_map(static fn (SqlFragment $argument): string => $argument->sql(), $arguments);
		$parameters = $this->mergeParameters($arguments);

		$sql = match ($expression->getOperation()) {
			ValueOperation::UPPER => 'UPPER(' . $sqlArguments[0] . ')',
			ValueOperation::LOWER => 'LOWER(' . $sqlArguments[0] . ')',
			ValueOperation::COALESCE => 'COALESCE(' . implode(', ', $sqlArguments) . ')',
			ValueOperation::ADD => '(' . implode(' + ', $sqlArguments) . ')',
			ValueOperation::CONCAT => $this->concatSql($sqlArguments),
		};

		return SqlFragment::withParameters($sql, $parameters);
	}

	private function concatSql(array $arguments): string
	{
		$type = strtolower($this->database->getType());

		return match (true) {
			str_contains($type, 'mysql'),
			str_contains($type, 'maria'),
			str_contains($type, 'sqlserver'),
			str_contains($type, 'sqlsrv') => 'CONCAT(' . implode(', ', $arguments) . ')',
			default => '(' . implode(' || ', $arguments) . ')',
		};
	}

	private function compileNestedQuery(
		SelectQuery $query,
		CycleTranslationContext $context,
		QueryUsage $usage,
	): SqlFragment {
		return $context->within($query, function () use ($query, $context, $usage): SqlFragment {
			$cycle = $this->database->select();
			$cycle->from($this->fromSource($query, $context)->toCycleFragment());

			[$columns] = $this->translateSelections($query, $context, false);

			if ($usage === QueryUsage::SCALAR_SUBQUERY || $usage === QueryUsage::IN_SUBQUERY) {
				if (count($columns) !== 1) {
					throw UnsupportedQueryException::forQuery(
						$query,
						$usage === QueryUsage::SCALAR_SUBQUERY
							? 'scalar subqueries require exactly one selection'
							: 'IN subqueries require exactly one selection',
					);
				}
			}

			$cycle->columns($columns === [] ? [SqlFragment::raw('1')->toCycleFragment()] : $columns);

			foreach ($query->getConditions() as $condition) {
				$cycle->where($this->translateCondition($condition, $context)->toCycleFragment());
			}

			foreach ($query->getGroups() as $group) {
				$cycle->groupBy($this->translateExpression($group, $context)->toCycleFragment());
			}

			foreach ($query->getHavingConditions() as $condition) {
				$cycle->having($this->translateCondition($condition, $context)->toCycleFragment());
			}

			foreach ($query->getSorts() as $sort) {
				$cycle->orderBy(
					$this->translateExpression($sort->getExpression(), $context)->toCycleFragment(),
					$sort->getDirection() === SortDirection::ASC ? CycleSelectQuery::SORT_ASC : CycleSelectQuery::SORT_DESC,
				);
			}

			$cycle->limit($query->getLimit());
			$cycle->offset($query->getOffset());

			$params = new QueryParameters();
			$sql = $cycle->sqlStatement($params);

			return SqlFragment::withParameters('(' . $sql . ')', $params->getParameters());
		});
	}

	private function fromSource(SelectQuery $query, CycleTranslationContext $context): SqlFragment
	{
		$source = $this->database->getPrefix() . $this->resolvePhysicalSource($query->getSource(), []);

		return SqlFragment::raw(
			$this->quote($source) . ' AS ' . $this->quote($context->aliasFor($query))
		);
	}

	private function resolvePhysicalSource(DefinitionInterface $definition, array $visited): string
	{
		if ($definition instanceof Collection) {
			$table = $definition->getTable();

			return $table !== '' ? $table : $definition->getName();
		}

		if ($definition instanceof ViewDefinition) {
			$name = $definition->getName();
			if (in_array($name, $visited, true)) {
				throw UnsupportedQueryException::forQuery(
					new SelectQuery($definition),
					sprintf("view source cycle detected at '%s'", $name),
				);
			}

			$source = $definition->getSource();
			if ($source === null) {
				throw UnsupportedQueryException::forQuery(
					new SelectQuery($definition),
					sprintf("view '%s' has no executable source", $name),
				);
			}

			$visited[] = $name;

			return $this->resolvePhysicalSource($source, $visited);
		}

		throw UnsupportedQueryException::forQuery(
			new SelectQuery($definition),
			sprintf("definition '%s' cannot be resolved to a physical source", $definition->getName()),
		);
	}

	/**
	 * @param list<SqlFragment> $fragments
	 * @return list<ParameterInterface>
	 */
	private function mergeParameters(array $fragments): array
	{
		$parameters = [];

		foreach ($fragments as $fragment) {
			array_push($parameters, ...$fragment->parameters());
		}

		return $parameters;
	}

	/**
	 * @param list<SqlFragment> $parts
	 */
	private function wrapSql(string $pattern, array $parts): SqlFragment
	{
		$sql = [];
		$parameters = [];

		foreach ($parts as $part) {
			$sql[] = $part->sql();
			array_push($parameters, ...$part->parameters());
		}

		return SqlFragment::withParameters(vsprintf($pattern, $sql), $parameters);
	}

	private function quote(string $identifier): string
	{
		return $this->database->getDriver()->getQueryCompiler()->quoteIdentifier($identifier);
	}
}
