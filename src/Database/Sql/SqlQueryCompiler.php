<?php

declare(strict_types=1);

namespace ON\Data\Database\Sql;

use ON\Data\Database\DatabasePlatformInterface;
use ON\Data\Database\Exception\UnsupportedQueryException;
use ON\Data\Database\Sql\Dialect\DialectInterface;
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
use ON\Data\Query\DerivedOutputColumns;
use ON\Data\Query\DerivedSelectQuery;
use ON\Data\Query\Exception\RelationLoaderException;
use ON\Data\Query\Expression\AggregateExpression;
use ON\Data\Query\Expression\AggregateFunction;
use ON\Data\Query\Expression\AliasedExpression;
use ON\Data\Query\Expression\FieldRef;
use ON\Data\Query\Expression\FunctionCallExpression;
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
use ON\Data\Query\QueryFunction\FunctionArguments;
use ON\Data\Query\QueryFunction\InvalidQueryFunctionException;
use ON\Data\Query\QueryFunction\QueryFunctionInterface;
use ON\Data\Query\QuerySourceInterface;
use ON\Data\Query\Relation\RelationRef;
use ON\Data\Query\Selection\SelectionItem;
use ON\Data\Query\SelectQuery;
use ON\Data\Query\Sort\Sort;
use ON\Data\Query\Sort\SortDirection;

/**
 * @internal
 */
final class SqlQueryCompiler
{
	public function __construct(
		private readonly DialectInterface $dialect,
		private readonly DatabasePlatformInterface $platform,
		private readonly ConversionGateway $gateway,
		private readonly string $tablePrefix = '',
	) {
	}

	public function compile(SelectQuery $query, ?int $maxRows = null): CompiledQuery
	{
		$context = new SqlTranslationContext($query);

		return $context->within($query, function () use ($query, $context, $maxRows): CompiledQuery {
			[$sql, , $columns] = $this->compileSelect($query, $context, true, maxRows: $maxRows);

			return new CompiledQuery($sql->sql(), $sql->parameters(), $columns);
		});
	}

	/**
	 * @return array{0: list<SqlFragment>, 1: list<SqlResultColumn>}
	 */
	private function translateSelections(
		SelectQuery $query,
		SqlTranslationContext $context,
		bool $root,
		bool $derived = false,
	): array {
		$selections = $query->getSelections()->getAll();

		$columns = [];
		$resultColumns = [];
		$usedNames = [];
		$implicitCounter = 0;

		if ($derived) {
			DerivedOutputColumns::assertUniqueNames($query);
		}

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
						static fn (SqlResultColumn $column): string => $column->logicalName(),
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
				if (
					$derived
					&& $expression->getSource() instanceof SelectQuery
					&& $expression->getSource()->getFrom() instanceof CollectionInterface
				) {
					$columnsToExpand = $this->expandCollectionStar($expression->getSource(), $context);
					foreach ($columnsToExpand as $column) {
						$columns[] = $column;
					}
				} elseif ($root && $expression->getSource() instanceof DerivedSelectQuery) {
					foreach ($this->expandSourceStar($expression, $context) as $column) {
						$columns[] = $column;
					}
				} else {
					$columns[] = $this->translateStar($expression, $context);
				}

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

			if ($root || $aliased || $derived) {
				if ($root && ! $selection->isExplicit() && $backendName === null) {
					if ($aliased) {
						$logicalName = $selection->getSelectionKey();
					}

					$backendName = $this->allocateImplicitAlias($usedNames, $implicitCounter);
				}

				$alias = $backendName
					?? ($aliased
						? $selectionExpression->getAlias()
						: (DerivedOutputColumns::expressionOutputName($expression) ?? $selection->getSelectionKey()));

				$columns[] = SqlFragment::withParameters(
					$sql->sql() . ' AS ' . $this->quote($alias),
					$sql->parameters(),
				);
			} else {
				$columns[] = $sql;
			}

			if ($root) {
				$resultColumns[] = new SqlResultColumn(
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
		SqlTranslationContext $context,
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
		SqlTranslationContext $context,
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
					ComparisonOperator::LIKE => 'LIKE',
					ComparisonOperator::NOT_LIKE => 'NOT LIKE',
				}),
				$rightSql,
			],
		);
	}

	private function translateLogical(
		LogicalCondition $condition,
		SqlTranslationContext $context,
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
		SqlTranslationContext $context,
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
		SqlTranslationContext $context,
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

	private function translateExists(ExistsCondition $condition, SqlTranslationContext $context): SqlFragment
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
		SqlTranslationContext $context,
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
			$expression instanceof FunctionCallExpression => $this->translateFunctionCall($expression, $context, $joinContext),
			$expression instanceof SubqueryExpression => $this->compileNestedQuery($expression->getQuery(), $context, QueryUsage::SCALAR_SUBQUERY),
			default => throw UnsupportedQueryException::forQuery($context->root(), 'unknown value expression type'),
		};
	}

	public function translateExpressionForFunction(
		ValueExpressionInterface $expression,
		SqlTranslationContext $context,
		?Join $joinContext,
	): SqlFragment {
		return $this->translateExpression($expression, $context, null, $joinContext);
	}

	public function quoteIdentifierForFunction(string $identifier): string
	{
		return $this->quoteQualified(...explode('.', $identifier));
	}

	private function translateFunctionCall(
		FunctionCallExpression $expression,
		SqlTranslationContext $context,
		?Join $joinContext = null,
	): SqlFragment {
		return $context->withinFunction($expression, function () use ($expression, $context, $joinContext): SqlFragment {
			$function = $expression->getFunction();
			$implementation = new $function();

			if (! $implementation instanceof QueryFunctionInterface) {
				throw InvalidQueryFunctionException::mustImplement($function);
			}

			$compilationContext = new SqlFunctionCompilationContext(
				$this,
				$context,
				$this->platform,
				$joinContext,
			);

			$compiled = $implementation->compile(
				$compilationContext,
				new FunctionArguments($expression->getArguments()),
			);

			return SqlFragment::withParameters(
				$compiled->getSql(),
				$compiled->getParameters(),
			);
		});
	}

	private function translateStar(StarExpression $expression, SqlTranslationContext $context): SqlFragment
	{
		return SqlFragment::raw($this->quote($context->aliasFor($expression->getSource())) . '.*');
	}

	/**
	 * @return list<SqlFragment>
	 */
	private function expandSourceStar(StarExpression $expression, SqlTranslationContext $context): array
	{
		$alias = $context->aliasFor($expression->getSource());
		$columns = [];

		foreach ($this->starResultColumns($expression) as $column) {
			$columns[] = SqlFragment::raw(
				$this->quoteQualified($alias, $column->backendName())
				. ' AS '
				. $this->quote($column->logicalName()),
			);
		}

		return $columns;
	}

	/**
	 * @return list<SqlFragment>
	 */
	private function expandCollectionStar(SelectQuery $query, SqlTranslationContext $context): array
	{
		$from = $query->getFrom();

		if (! $from instanceof CollectionInterface) {
			return [];
		}

		$alias = $context->aliasFor($query);
		$columns = [];

		foreach ($from->getVisibleFields() as $fieldName) {
			$field = $from->getField($fieldName);
			$columns[] = SqlFragment::raw(
				$this->quoteQualified($alias, $field->getColumn())
				. ' AS '
				. $this->quote($field->getName()),
			);
		}

		return $columns;
	}

	/**
	 * @return list<SqlResultColumn>
	 */
	private function starResultColumns(StarExpression $expression): array
	{
		$source = $expression->getSource();

		if ($source instanceof SelectQuery && ! $source->isDerivedSource()) {
			$columns = [];

			foreach ($source->getCollection()->getVisibleFields() as $fieldName) {
				$field = $source->getCollection()->getField($fieldName);
				$columns[] = new SqlResultColumn($field->getColumn(), $field->getName(), true, $field);
			}

			return $columns;
		}

		if ($source instanceof SelectQuery || $source instanceof DerivedSelectQuery) {
			return array_map(
				static fn (string $name): SqlResultColumn => new SqlResultColumn($name, $name, true),
				$this->derivedSelectionNames($source),
			);
		}

		return [];
	}

	/**
	 * @return list<string>
	 */
	private function derivedSelectionNames(SelectQuery|DerivedSelectQuery $source): array
	{
		if ($source instanceof DerivedSelectQuery) {
			return DerivedOutputColumns::names($source->getInnerQuery());
		}

		return DerivedOutputColumns::names($source);
	}

	private function translateRawSql(RawSqlExpression $expression): SqlFragment
	{
		return SqlFragment::withParameters(
			$expression->getSql(),
			$expression->getParameters(),
		);
	}

	private function translateFieldRef(
		FieldRef $field,
		SqlTranslationContext $context,
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

		return SqlFragment::raw($this->quoteQualified(
			$context->aliasFor($source),
			$field->getField()->getColumn(),
		));
	}

	private function translateSourceField(
		SourceFieldExpression $field,
		SqlTranslationContext $context,
	): SqlFragment {
		$context->assertSourceAccessible($field->getSource());

		$source = $field->getSource();

		if (
			$source instanceof SelectQuery
			&& $context->isCurrent($source)
			&& $source->getFrom() instanceof CollectionInterface
			&& $source->getFrom()->hasField($field->getName())
		) {
			return SqlFragment::raw($this->quoteQualified(
				$context->aliasFor($source),
				$source->getFrom()->getField($field->getName())->getColumn(),
			));
		}

		return SqlFragment::raw($this->quoteQualified(
			$context->aliasFor($source),
			$field->getName(),
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

		return SqlFragment::withParameters('?', [$value]);
	}

	private function translateAggregate(
		AggregateExpression $expression,
		SqlTranslationContext $context,
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
			AggregateFunction::AVG => 'AVG(' . $inner->sql() . ')',
			AggregateFunction::MIN => 'MIN(' . $inner->sql() . ')',
			AggregateFunction::MAX => 'MAX(' . $inner->sql() . ')',
		};

		return SqlFragment::withParameters($sql, $inner->parameters());
	}

	private function translateValueOperation(
		ValueOperationExpression $expression,
		SqlTranslationContext $context,
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
			ValueOperation::CONCAT => $this->dialect->compileConcat($sqlArguments),
		};

		return SqlFragment::withParameters($sql, $parameters);
	}

	private function translateWindowFunction(
		WindowFunctionExpression $expression,
		SqlTranslationContext $context,
		?Join $joinContext = null,
	): SqlFragment {
		$window = $expression->getWindow();
		$parts = [];
		$parameters = [];

		if ($window !== null && $window->getPartitionBy() !== []) {
			$partition = SqlFragment::join(', ', array_map(
				fn (ValueExpressionInterface $partition): SqlFragment => $this->translateExpression($partition, $context, null, $joinContext),
				$window->getPartitionBy(),
			));
			$parts[] = 'PARTITION BY ' . $partition->sql();
			array_push($parameters, ...$partition->parameters());
		}

		if ($window !== null && $window->getOrderings() !== []) {
			$order = SqlFragment::join(', ', array_map(
				fn (Sort $sort): SqlFragment => $this->translateSort($sort, $context, $joinContext),
				$window->getOrderings(),
			));
			$parts[] = 'ORDER BY ' . $order->sql();
			array_push($parameters, ...$order->parameters());
		}

		return SqlFragment::withParameters(
			$expression->getFunction()->value . '() OVER (' . implode(' ', $parts) . ')',
			$parameters,
		);
	}

	private function translateSort(Sort $sort, SqlTranslationContext $context, ?Join $joinContext = null): SqlFragment
	{
		$expression = $this->translateExpression($sort->getExpression(), $context, null, $joinContext);

		return SqlFragment::withParameters(
			$expression->sql() . ($sort->getDirection() === SortDirection::ASC ? ' ASC' : ' DESC'),
			$expression->parameters(),
		);
	}

	private function compileNestedQuery(
		SelectQuery $query,
		SqlTranslationContext $context,
		QueryUsage $usage,
	): SqlFragment {
		return $context->within($query, function () use ($query, $context, $usage): SqlFragment {
			[$sql, $selectionCount] = $this->compileSelect($query, $context, false);

			if ($usage === QueryUsage::SCALAR_SUBQUERY || $usage === QueryUsage::IN_SUBQUERY) {
				if ($selectionCount !== 1) {
					throw UnsupportedQueryException::forQuery(
						$query,
						$usage === QueryUsage::SCALAR_SUBQUERY
							? 'scalar subqueries require exactly one selection'
							: 'IN subqueries require exactly one selection',
					);
				}
			}

			return SqlFragment::withParameters('(' . $sql->sql() . ')', $sql->parameters());
		});
	}

	/**
	 * @return array{SqlFragment, int, list<SqlResultColumn>}
	 */
	private function compileSelect(
		SelectQuery $query,
		SqlTranslationContext $context,
		bool $root,
		bool $derived = false,
		?int $maxRows = null,
	): array {
		$from = $this->fromSource($query, $context);
		[$selections, $resultColumns] = $this->translateSelections($query, $context, $root, $derived);
		$condition = fn (ConditionInterface $condition): SqlFragment => $this->translateCondition($condition, $context);
		$where = array_map($condition, $query->getConditions());
		$groups = array_map(
			fn (ValueExpressionInterface $expression): SqlFragment => $this->translateExpression($expression, $context),
			$query->getGroups(),
		);
		$having = array_map($condition, $query->getHavingConditions());
		$sorts = array_map(fn (Sort $sort): SqlFragment => $this->translateSort($sort, $context), $query->getSorts());
		$joins = $this->translateJoins($query, $context);

		$limit = $query->getLimit();
		if ($maxRows !== null) {
			$limit = $limit === null ? $maxRows : min($limit, $maxRows);
		}

		$parts = [
			$this->dialect->compileSelectPrefix($limit),
			SqlFragment::join(', ', $selections === [] ? [SqlFragment::raw($root ? '*' : '1')] : $selections),
			SqlFragment::raw('FROM'),
			$from,
			...$joins,
		];
		foreach ([
			['WHERE', ' AND ', $where],
			['GROUP BY', ', ', $groups],
			['HAVING', ' AND ', $having],
			['ORDER BY', ', ', $sorts],
		] as [$keyword, $separator, $fragments]) {
			if ($fragments !== []) {
				$parts[] = SqlFragment::raw($keyword);
				$parts[] = SqlFragment::join($separator, $fragments);
			}
		}

		$pagination = $this->dialect->compileLimitOffset($limit, $query->getOffset(), $sorts !== [], ! $root);
		if ($pagination->sql() !== '') {
			$parts[] = $pagination;
		}

		return [SqlFragment::join(' ', $parts), count($selections), $resultColumns];
	}

	private function fromSource(SelectQuery $query, SqlTranslationContext $context): SqlFragment
	{
		$from = $query->getFrom();
		if ($from instanceof DerivedSelectQuery) {
			$innerQuery = $from->getInnerQuery();
			$inner = $context->within(
				$innerQuery,
				fn (): SqlFragment => $this->compileSelect($innerQuery, $context, false, true)[0],
			);

			return SqlFragment::withParameters(
				'(' . $inner->sql() . ') AS ' . $this->quote($from->requireAlias()),
				$inner->parameters(),
			);
		}

		if (! $from instanceof CollectionInterface) {
			throw UnsupportedQueryException::forQuery($query, 'query source must be a collection or aliased subquery');
		}

		return SqlFragment::raw(
			$this->physicalTable($from) . ' AS ' . $this->quote($context->aliasFor($query)),
		);
	}

	private function physicalTable(CollectionInterface $collection): string
	{
		$table = $collection->getTable();

		return $this->dialect->quoteTable($table !== '' ? $table : $collection->getName(), $this->tablePrefix);
	}

	/** @return list<SqlFragment> */
	private function translateJoins(SelectQuery $query, SqlTranslationContext $context): array
	{
		$joins = [];
		foreach ($query->getJoins() as $join) {
			$type = match ($join->getType()) {
				JoinType::INNER => 'INNER',
				JoinType::LEFT => 'LEFT',
			};
			$alias = $this->quote($context->aliasFor($join));
			$conditions = $this->translateJoinConditions($query, $join, $context);
			$joins[] = SqlFragment::withParameters(
				$type . ' JOIN ' . $this->physicalTable($join->getCollection()) . ' AS ' . $alias . ' ON ' . $conditions->sql(),
				$conditions->parameters(),
			);
		}

		return $joins;
	}

	/**
	 * @param list<SqlFragment> $fragments
	 * @return list<SqlFragment>
	 */
	private function parenthesize(array $fragments): array
	{
		return array_map(
			static fn (SqlFragment $fragment): SqlFragment => SqlFragment::withParameters(
				'(' . $fragment->sql() . ')',
				$fragment->parameters(),
			),
			$fragments,
		);
	}

	private function translateJoinConditions(
		SelectQuery $query,
		Join $join,
		SqlTranslationContext $context,
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

		return SqlFragment::join(' AND ', $this->parenthesize($fragments));
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
	 * @return list<mixed>
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
		return $this->dialect->quoteIdentifier($identifier);
	}

	private function quoteQualified(string ...$identifiers): string
	{
		return implode('.', array_map($this->quote(...), $identifiers));
	}
}
