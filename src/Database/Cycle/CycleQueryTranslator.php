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
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Field\FieldInterface;
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
use ON\Data\Query\DerivedQuerySource;
use ON\Data\Query\Exception\RelationLoaderException;
use ON\Data\Query\Expression\AggregateExpression;
use ON\Data\Query\Expression\AggregateFunction;
use ON\Data\Query\Expression\AliasedExpression;
use ON\Data\Query\Expression\FieldRef;
use ON\Data\Query\Expression\LiteralExpression;
use ON\Data\Query\Expression\RawSqlExpression;
use ON\Data\Query\Expression\SourceFieldExpression;
use ON\Data\Query\Expression\StarExpression;
use ON\Data\Query\Expression\SubqueryExpression;
use ON\Data\Query\Expression\ValueExpressionInterface;
use ON\Data\Query\Expression\ValueOperation;
use ON\Data\Query\Expression\ValueOperationExpression;
use ON\Data\Query\Expression\WindowFunctionExpression;
use ON\Data\Query\Join;
use ON\Data\Query\JoinType;
use ON\Data\Query\QuerySourceInterface;
use ON\Data\Query\Relation\RelationRef;
use ON\Data\Query\Selection\SelectionItem;
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

	public function translate(SelectQuery $query): CycleTranslatedQuery
	{
		$context = new CycleTranslationContext($query, $this->database->getDriver()->getQueryCompiler());

		return $context->within($query, function () use ($query, $context): CycleTranslatedQuery {
			$cycle = $this->database->select();
			$cycle->from($this->fromSource($query, $context));

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

			$this->translateJoins($query, $cycle, $context);

			$cycle->limit($query->getLimit());
			$cycle->offset($query->getOffset());

			return new CycleTranslatedQuery($cycle, $resultColumns);
		});
	}

	/**
	 * @return array{0: list<string|FragmentInterface>, 1: list<CycleResultColumn>}
	 */
	private function translateSelections(SelectQuery $query, CycleTranslationContext $context, bool $root): array
	{
		$selections = $query->getSelections()->getAll();
		if ($root && $query->getSelections()->getExplicit() === []) {
			throw UnsupportedQueryException::forQuery($query, 'root execution requires at least one explicit selection');
		}

		$columns = [];
		$resultColumns = [];
		$usedNames = [];
		$implicitCounter = 0;

		if ($root) {
			foreach ($selections as $selection) {
				if (! $selection->isExplicit()) {
					continue;
				}

				$selectionExpression = $selection->getExpression();
				$expression = $selectionExpression instanceof AliasedExpression
					? $selectionExpression->getExpression()
					: $selectionExpression;
				$logicalNames = $expression instanceof StarExpression
					? array_map(
						static fn (CycleResultColumn $column): string => $column->logicalName(),
						$this->starResultColumns($expression),
					)
					: [$this->resolveRootResultName($query, $selection, $expression)];

				foreach ($logicalNames as $logicalName) {
					if (isset($usedNames[$logicalName])) {
						throw UnsupportedQueryException::forQuery($query, sprintf(
							"duplicate root result name '%s' is not supported",
							$logicalName,
						));
					}

					$usedNames[$logicalName] = true;
				}
			}
		}

		foreach ($selections as $index => $selection) {
			$selectionExpression = $selection->getExpression();
			$aliased = $selectionExpression instanceof AliasedExpression;
			$expression = $aliased ? $selectionExpression->getExpression() : $selectionExpression;
			$logicalName = null;
			$backendName = null;
			$field = $expression instanceof FieldRef ? $expression->getField() : null;
			$visible = ! $root || $selection->isExplicit();

			if ($expression instanceof StarExpression) {
				$columns[] = $this->translateStar($expression, $context)->toCycleFragment();

				if ($root) {
					foreach ($this->starResultColumns($expression) as $resultColumn) {
						$resultColumns[] = $resultColumn;
					}
				}

				continue;
			}

			if ($root && $selection->isExplicit()) {
				$logicalName = $this->resolveRootResultName($query, $selection, $expression);
				$backendName = $logicalName;
			}

			$sql = $this->translateExpression($expression, $context);

			if ($root || $aliased) {
				if ($root && $selection->isImplicit()) {
					$backendName = $this->allocateImplicitAlias($usedNames, $implicitCounter);
				}

				$alias = $backendName ?? $selectionExpression->getAlias();

				$columns[] = SqlFragment::withParameters(
					$sql->sql() . ' AS ' . $this->quoteResultAlias($alias),
					$sql->parameters(),
				)->toCycleFragment();
			} else {
				$columns[] = $sql->toCycleFragment();
			}

			if ($root) {
				$resultColumns[] = new CycleResultColumn(
					$backendName ?? sprintf('__ondata_implicit_%d', $index),
					$logicalName ?? sprintf('__ondata_implicit_%d', $index),
					$visible,
					$field,
				);
			}
		}

		return [$columns, $resultColumns];
	}

	/**
	 * @param array<string, true> $usedNames
	 */
	private function allocateImplicitAlias(array &$usedNames, int &$counter): string
	{
		do {
			$alias = sprintf('__ondata_implicit_%d', $counter++);
		} while (isset($usedNames[$alias]));

		$usedNames[$alias] = true;

		return $alias;
	}

	private function resolveRootResultName(
		SelectQuery $query,
		SelectionItem $selection,
		ValueExpressionInterface $expression,
	): string {
		$selectionExpression = $selection->getExpression();

		if ($selectionExpression instanceof AliasedExpression) {
			return $selection->getSelectionKey();
		}

		if ($expression instanceof FieldRef) {
			$path = $expression->getPath();

			return count($path) === 1
				? $selection->getSelectionKey()
				: implode('.', $path);
		}

		if ($expression instanceof SourceFieldExpression) {
			return $expression->getName();
		}

		throw UnsupportedQueryException::forQuery(
			$query,
			'unaliased computed, aggregate, and subquery root selections are not supported',
		);
	}

	private function translateCondition(
		ConditionInterface $condition,
		CycleTranslationContext $context,
		?Join $joinContext = null,
	): SqlFragment {
		return match (true) {
			$condition instanceof ComparisonCondition => $this->translateComparison($condition, $context, $joinContext),
			$condition instanceof NullCondition => $this->translateNullCondition($condition, $context, $joinContext),
			$condition instanceof LogicalCondition => $this->translateLogical($condition, $context, $joinContext),
			$condition instanceof NotCondition => $this->wrapSql(
				'NOT (%s)',
				[$this->translateCondition($condition->getCondition(), $context, $joinContext)],
			),
			$condition instanceof InCondition => $this->translateIn($condition, $context, $joinContext),
			$condition instanceof ExistsCondition => $this->translateExists($condition, $context),
			default => throw UnsupportedQueryException::forQuery($context->root(), 'unknown condition type'),
		};
	}

	private function translateComparison(
		ComparisonCondition $condition,
		CycleTranslationContext $context,
		?Join $joinContext = null,
	): SqlFragment {
		$left = $condition->getLeft();
		$right = $condition->getRight();

		$leftSql = $this->translateExpression($left, $context, null, $joinContext);
		$rightSql = $this->translateExpression(
			$right,
			$context,
			$left instanceof FieldRef && $right instanceof LiteralExpression ? $left->getField() : null,
			$joinContext,
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

	private function translateLogical(
		LogicalCondition $condition,
		CycleTranslationContext $context,
		?Join $joinContext = null,
	): SqlFragment {
		$operator = $condition->getOperator() === LogicalOperator::AND ? ' AND ' : ' OR ';
		$parts = array_map(
			fn (ConditionInterface $nested): SqlFragment => $this->translateCondition($nested, $context, $joinContext),
			$condition->getConditions(),
		);

		$sql = '(' . implode(
			$operator,
			array_map(static fn (SqlFragment $fragment): string => $fragment->sql(), $parts),
		) . ')';

		return SqlFragment::withParameters($sql, $this->mergeParameters($parts));
	}

	private function translateNullCondition(
		NullCondition $condition,
		CycleTranslationContext $context,
		?Join $joinContext = null,
	): SqlFragment {
		$expression = $this->translateExpression($condition->getExpression(), $context, null, $joinContext);

		return SqlFragment::withParameters(
			sprintf(
				'%s %s',
				$expression->sql(),
				$condition->getOperator() === NullOperator::IS_NULL ? 'IS NULL' : 'IS NOT NULL',
			),
			$expression->parameters(),
		);
	}

	private function translateIn(
		InCondition $condition,
		CycleTranslationContext $context,
		?Join $joinContext = null,
	): SqlFragment {
		$expression = $this->translateExpression($condition->getExpression(), $context, null, $joinContext);
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
				$joinContext,
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
		?Join $joinContext = null,
	): SqlFragment {
		return match (true) {
			$expression instanceof FieldRef => $this->translateFieldRef($expression, $context, $joinContext),
			$expression instanceof SourceFieldExpression => $this->translateSourceField($expression, $context),
			$expression instanceof LiteralExpression => $this->translateLiteral($expression, $literalFieldContext),
			$expression instanceof RawSqlExpression => $this->translateRawSql($expression),
			$expression instanceof AggregateExpression => $this->translateAggregate($expression, $context, $joinContext),
			$expression instanceof ValueOperationExpression => $this->translateValueOperation($expression, $context, $joinContext),
			$expression instanceof WindowFunctionExpression => $this->translateWindowFunction($expression, $context, $joinContext),
			$expression instanceof SubqueryExpression => $this->compileNestedQuery($expression->getQuery(), $context, QueryUsage::SCALAR_SUBQUERY),
			default => throw UnsupportedQueryException::forQuery($context->root(), 'unknown value expression type'),
		};
	}

	private function translateStar(StarExpression $expression, CycleTranslationContext $context): SqlFragment
	{
		return SqlFragment::raw($this->quote($context->aliasFor($expression->getSource())) . '.*');
	}

	/**
	 * @return list<CycleResultColumn>
	 */
	private function starResultColumns(StarExpression $expression): array
	{
		$source = $expression->getSource();

		if ($source instanceof SelectQuery) {
			$columns = [];

			foreach ($source->getCollection()->getVisibleFields() as $fieldName) {
				$field = $source->getCollection()->getField($fieldName);
				$columns[] = new CycleResultColumn($field->getColumn(), $field->getName(), true, $field);
			}

			return $columns;
		}

		if ($source instanceof DerivedQuerySource) {
			return array_map(
				static fn (string $name): CycleResultColumn => new CycleResultColumn($name, $name, true),
				$this->derivedSelectionNames($source),
			);
		}

		return [];
	}

	/**
	 * @return list<string>
	 */
	private function derivedSelectionNames(DerivedQuerySource $source): array
	{
		$names = [];

		foreach ($source->getQuery()->getSelections()->getExplicit() as $selection) {
			$expression = $selection->getExpression();
			if ($expression instanceof AliasedExpression) {
				$names[] = $expression->getAlias();

				continue;
			}

			if ($expression instanceof FieldRef) {
				$names[] = $expression->getField()->getColumn();

				continue;
			}

			if ($expression instanceof SourceFieldExpression) {
				$names[] = $expression->getName();

				continue;
			}

			if ($expression instanceof StarExpression && $expression->getSource() instanceof SelectQuery) {
				foreach ($expression->getSource()->getCollection()->getVisibleFields() as $fieldName) {
					$names[] = $expression->getSource()->getCollection()->getField($fieldName)->getColumn();
				}
			}
		}

		return array_values(array_unique($names));
	}

	private function translateRawSql(RawSqlExpression $expression): SqlFragment
	{
		return SqlFragment::withParameters(
			$expression->getSql(),
			array_map(static fn (mixed $value): Parameter => new Parameter($value), $expression->getParameters()),
		);
	}

	private function translateFieldRef(
		FieldRef $field,
		CycleTranslationContext $context,
		?Join $joinContext = null,
	): SqlFragment {
		$context->assertAccessible($field);
		$query = $field->getQuery();

		if (! $context->isCurrent($query) && ! $context->isAncestor($query)) {
			throw UnsupportedQueryException::forQuery(
				$context->root(),
				sprintf("Field '%s' is referenced outside the active query scope.", $field->getName()),
			);
		}

		try {
			$source = $this->resolveFieldSource($field->getSource(), $context->root(), $joinContext);
		} catch (RelationLoaderException $exception) {
			throw UnsupportedQueryException::forQuery($context->root(), $exception->getMessage());
		}

		return SqlFragment::raw($this->quote(
			$context->aliasFor($source) . '.' . $field->getField()->getColumn()
		));
	}

	private function translateSourceField(
		SourceFieldExpression $field,
		CycleTranslationContext $context,
	): SqlFragment {
		$context->assertSourceAccessible($field->getSource());

		return SqlFragment::raw($this->quote(
			$context->aliasFor($field->getSource()) . '.' . $field->getName()
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

	private function translateAggregate(
		AggregateExpression $expression,
		CycleTranslationContext $context,
		?Join $joinContext = null,
	): SqlFragment {
		if ($expression->getExpression() instanceof StarExpression) {
			return SqlFragment::raw('COUNT(*)');
		}

		$inner = $this->translateExpression($expression->getExpression(), $context, null, $joinContext);

		$sql = match ($expression->getFunction()) {
			AggregateFunction::COUNT => $expression->getExpression() instanceof StarExpression
				? 'COUNT(*)'
				: 'COUNT(' . $inner->sql() . ')',
			AggregateFunction::COUNT_DISTINCT => 'COUNT(DISTINCT ' . $inner->sql() . ')',
			AggregateFunction::SUM => 'SUM(' . $inner->sql() . ')',
		};

		return SqlFragment::withParameters($sql, $inner->parameters());
	}

	private function translateValueOperation(
		ValueOperationExpression $expression,
		CycleTranslationContext $context,
		?Join $joinContext = null,
	): SqlFragment {
		$arguments = array_map(
			fn (ValueExpressionInterface $argument): SqlFragment => $this->translateExpression($argument, $context, null, $joinContext),
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

	private function translateWindowFunction(
		WindowFunctionExpression $expression,
		CycleTranslationContext $context,
		?Join $joinContext = null,
	): SqlFragment {
		$window = $expression->getWindow();
		$parts = [];
		$parameters = [];

		if ($window !== null && $window->getPartitionBy() !== []) {
			$partition = array_map(
				fn (ValueExpressionInterface $partition): SqlFragment => $this->translateExpression($partition, $context, null, $joinContext),
				$window->getPartitionBy(),
			);
			$parts[] = 'PARTITION BY ' . implode(', ', array_map(static fn (SqlFragment $fragment): string => $fragment->sql(), $partition));
			array_push($parameters, ...$this->mergeParameters($partition));
		}

		if ($window !== null && $window->getOrderings() !== []) {
			$order = array_map(
				fn ($sort): SqlFragment => SqlFragment::withParameters(
					$this->translateExpression($sort->getExpression(), $context, null, $joinContext)->sql()
						. ' '
						. ($sort->getDirection() === SortDirection::ASC ? 'ASC' : 'DESC'),
					$this->translateExpression($sort->getExpression(), $context, null, $joinContext)->parameters(),
				),
				$window->getOrderings(),
			);
			$parts[] = 'ORDER BY ' . implode(', ', array_map(static fn (SqlFragment $fragment): string => $fragment->sql(), $order));
			array_push($parameters, ...$this->mergeParameters($order));
		}

		return SqlFragment::withParameters(
			$expression->getFunction()->value . '() OVER (' . implode(' ', $parts) . ')',
			$parameters,
		);
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
			[$cycle, $columns] = $this->compileCycleSelect($query, $context, false);

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

			$params = new QueryParameters();
			$sql = $cycle->sqlStatement($params);

			return SqlFragment::withParameters('(' . $sql . ')', $params->getParameters());
		});
	}

	/**
	 * @return array{0: CycleSelectQuery, 1: list<string|FragmentInterface>, 2: list<CycleResultColumn>}
	 */
	private function compileCycleSelect(SelectQuery $query, CycleTranslationContext $context, bool $root): array
	{
		$cycle = $this->database->select();
		$cycle->from($this->fromSource($query, $context));

		[$columns, $resultColumns] = $this->translateSelections($query, $context, $root);
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

		$this->translateJoins($query, $cycle, $context);

		$cycle->limit($query->getLimit());
		$cycle->offset($query->getOffset());

		return [$cycle, $columns, $resultColumns];
	}

	private function fromSource(SelectQuery $query, CycleTranslationContext $context): FragmentInterface
	{
		if ($query->getFrom() instanceof DerivedQuerySource) {
			$derived = $query->getFrom();
			$inner = $this->compileNestedQuery($derived->getQuery(), $context, QueryUsage::EXISTS_SUBQUERY);

			return SqlFragment::withParameters(
				$inner->sql() . ' AS ' . $this->quote($context->aliasFor($derived)),
				$inner->parameters(),
			)->toCycleFragment();
		}

		$source = $this->database->getPrefix() . $this->resolvePhysicalSource($query->getCollection());

		return SqlFragment::raw(
			$this->quote($source) . ' AS ' . $this->quote($context->aliasFor($query))
		)->toCycleFragment();
	}

	private function resolvePhysicalSource(CollectionInterface $collection): string
	{
		$table = $collection->getTable();

		return $table !== '' ? $table : $collection->getName();
	}

	private function translateJoins(SelectQuery $query, CycleSelectQuery $cycle, CycleTranslationContext $context): void
	{
		foreach ($query->getJoins() as $join) {
			$cycle->join(
				$join->getType() === JoinType::INNER ? 'INNER' : 'LEFT',
				$this->database->getPrefix() . $this->resolvePhysicalSource($join->getCollection()),
				$context->aliasFor($join),
				$this->translateJoinConditions($query, $join, $context)->toCycleFragment(),
			);
		}
	}

	private function translateJoinConditions(
		SelectQuery $query,
		Join $join,
		CycleTranslationContext $context,
	): SqlFragment {
		$conditions = $join->getConditions();

		if ($conditions === []) {
			throw UnsupportedQueryException::forQuery(
				$query,
				sprintf('Join "%s" requires at least one ON condition.', $join->getName()),
			);
		}

		$fragments = array_map(
			fn (ConditionInterface $condition): SqlFragment => $this->translateCondition($condition, $context, $join),
			$conditions,
		);

		return SqlFragment::withParameters(
			implode(
				' AND ',
				array_map(static fn (SqlFragment $fragment): string => '(' . $fragment->sql() . ')', $fragments),
			),
			$this->mergeParameters($fragments),
		);
	}

	private function resolveFieldSource(
		QuerySourceInterface $source,
		SelectQuery $query,
		?Join $joinContext = null,
	): QuerySourceInterface {
		if (! $source instanceof RelationRef) {
			return $source;
		}

		if ($joinContext === null) {
			return $source->getJoinedSource();
		}

		if ($source->hasJoinedSource()) {
			return $source->getJoinedSource();
		}

		$path = implode('.', $source->getPath());

		throw UnsupportedQueryException::forQuery(
			$query,
			sprintf(
				'Join "%s" cannot use unresolved relation path "%s" inside ON conditions.',
				$joinContext->getName(),
				$path,
			),
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

	private function quoteResultAlias(string $alias): string
	{
		return '"' . str_replace('"', '""', $alias) . '"';
	}
}
