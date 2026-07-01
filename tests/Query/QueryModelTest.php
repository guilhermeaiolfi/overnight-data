<?php

declare(strict_types=1);

namespace Tests\ON\Data\Query;

use DateTimeImmutable;
use Error;
use InvalidArgumentException;
use LogicException;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\Query\Condition\ComparisonCondition;
use ON\Data\Query\Condition\ComparisonOperator;
use ON\Data\Query\Condition\ExistsCondition;
use ON\Data\Query\Condition\InCondition;
use ON\Data\Query\Condition\LogicalCondition;
use ON\Data\Query\Condition\LogicalOperator;
use ON\Data\Query\Condition\NotCondition;
use ON\Data\Query\Condition\NullCondition;
use ON\Data\Query\Condition\NullOperator;
use ON\Data\Query\Exception\RelationSelectionException;
use ON\Data\Query\Exception\UnknownQueryExpressionException;
use ON\Data\Query\Exception\UnknownQueryFieldException;
use ON\Data\Query\Exception\UnknownQueryMemberException;
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
use ON\Data\Query\ExpressionFactory;
use function ON\Data\Query\query;
use ON\Data\Query\Relation\LoadRuntime;
use ON\Data\Query\Relation\LoadStrategy;
use ON\Data\Query\Selection\SelectionItem;
use ON\Data\Query\Selection\SelectionList;
use ON\Data\Query\Selection\SelectionReason;
use ON\Data\Query\SelectQuery;
use ON\Data\Query\Sort\Sort;
use ON\Data\Query\Sort\SortDirection;
use function ON\Data\Query\x;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tests\ON\Data\Fixture\AbstractTestLoader;
use Tests\ON\Data\Fixture\CustomRelation;
use Tests\ON\Data\Fixture\RequiredCtorLoader;
use TypeError;

final class QueryModelTest extends TestCase
{
	public function testQueryCreationSupportsCollectionsViewsAndCallbacks(): void
	{
		$registry = $this->makeRegistry();
		$collection = $registry->getCollection('users');

		self::assertInstanceOf(CollectionInterface::class, $collection);

		$collectionQuery = query($collection);

		self::assertInstanceOf(SelectQuery::class, $collectionQuery);
		self::assertSame($collection, $collectionQuery->getCollection());

		$callbackResult = query($collection, function (SelectQuery $query): string {
			$query->select($query->id);

			return 'ignored';
		});

		$voidResult = query($collection, function (SelectQuery $query): void {
			$query->where(x()->eq($query->active, true));
		});

		$arrowResult = query($collection, fn (SelectQuery $query) => $query->select($query->title));

		self::assertSame($collection, $callbackResult->getCollection());
		self::assertCount(1, $callbackResult->getSelections());
		self::assertSame($collection, $voidResult->getCollection());
		self::assertCount(1, $voidResult->getConditions());
		self::assertSame($collection, $arrowResult->getCollection());
		self::assertCount(1, $arrowResult->getSelections());
	}

	public function testFieldReferencesAreQueryScopedCachedAndBackedByDefinitionFields(): void
	{
		$users = $this->makeRegistry()->getCollection('users');
		$first = query($users);
		$second = query($users);

		self::assertInstanceOf(FieldRef::class, $first->id);
		self::assertSame($first->id, $first->id);
		self::assertSame($first->id, $first->field('id'));
		self::assertSame('id', $first->id->getName());
		self::assertSame($first, $first->id->getQuery());
		self::assertSame($users->getField('id'), $first->id->getField());
		self::assertNotSame($first->id, $second->id);
		self::assertSame($first->id->getField(), $second->id->getField());
	}

	public function testUnknownOrRelationFieldAccessThrows(): void
	{
		$users = $this->makeRegistry()->getCollection('users');
		$query = query($users);

		try {
			$query->missing;
			self::fail('Expected missing field access to throw.');
		} catch (UnknownQueryMemberException $exception) {
			self::assertSame("Unknown query member 'missing' on definition 'users'.", $exception->getMessage());
		}

		$this->expectException(UnknownQueryFieldException::class);
		$query->field('posts');
	}

	public function testAccessingFieldDoesNotCreateASelection(): void
	{
		$query = query($this->makeRegistry()->getCollection('users'));
		$field = $query->name;

		self::assertInstanceOf(FieldRef::class, $field);
		self::assertCount(0, $query->getSelections());
		self::assertSame([], $query->getSelections()->getAll());
	}

	public function testSelectCreatesAnExplicitSelectionUsingTheCachedFieldReference(): void
	{
		$query = query($this->makeRegistry()->getCollection('users'));
		$field = $query->name;
		$returned = $query->select($field);
		$selections = $query->getSelections()->getAll();

		self::assertSame($query, $returned);
		self::assertCount(1, $selections);
		self::assertInstanceOf(SelectionItem::class, $selections[0]);
		self::assertSame($field, $selections[0]->getExpression());
		self::assertTrue($selections[0]->isExplicit());
		self::assertSame([], $selections[0]->getReasons());
	}

	public function testSelectionConstructorNormalizesAndDeduplicatesReasons(): void
	{
		$query = query($this->makeRegistry()->getCollection('users'));
		$selection = new SelectionItem($query->id, false, [' relation-key ', 'relation-key', ' result-grouping-key ']);

		self::assertSame(['relation-key', 'result-grouping-key'], $selection->getReasons());
		self::assertTrue($selection->hasReason('relation-key'));
		self::assertTrue($selection->hasReason(' result-grouping-key '));
	}

	public function testSelectableExpressionsOwnSelectionKeys(): void
	{
		$query = query($this->makeRegistry()->getCollection('users'));
		$aliased = $query->name->upper()->as('display_name');
		$selection = new SelectionItem($aliased);

		self::assertSame('name', $query->name->getSelectionKey());
		self::assertSame('display_name', $aliased->getSelectionKey());
		self::assertSame('display_name', $selection->getSelectionKey());
	}

	public function testUnaliasedComputedSelectionsRequireStableExpressionKeys(): void
	{
		$query = query($this->makeRegistry()->getCollection('users'));

		$this->expectException(LogicException::class);
		$this->expectExceptionMessage('does not expose a stable selection key');
		$query->name->upper()->getSelectionKey();
	}

	public function testSelectAppendsExpressionsPreservesOrderAndExposesAliases(): void
	{
		$query = query($this->makeRegistry()->getCollection('users'));
		$firstReturn = $query->select($query->id);
		$secondReturn = $query->select($query->title->as(' headline '));
		$selections = $query->getSelections()->getAll();

		self::assertSame($query, $firstReturn);
		self::assertSame($query, $secondReturn);
		self::assertCount(2, $selections);
		self::assertSame($query->id, $selections[0]->getExpression());
		self::assertInstanceOf(AliasedExpression::class, $selections[1]->getExpression());
		self::assertSame('headline', $selections[1]->getExpression()->getAlias());
		self::assertSame($query->title, $selections[1]->getExpression()->getExpression());
	}

	public function testSelectRejectsEmptyCalls(): void
	{
		$this->expectException(InvalidArgumentException::class);
		query($this->makeRegistry()->getCollection('users'))->select();
	}

	public function testWhereAppendsConditionsPreservesOrderAndReturnsSameQuery(): void
	{
		$query = query($this->makeRegistry()->getCollection('users'));
		$first = x()->eq($query->active, true);
		$second = x()->or(
			x()->isNull($query->deletedAt),
			x()->gt($query->deletedAt, new DateTimeImmutable('2026-01-01')),
		);
		$returned = $query->where($first)->where($second);

		self::assertSame($query, $returned);
		self::assertSame([$first, $second], $query->getConditions());
		self::assertInstanceOf(LogicalCondition::class, $query->getConditions()[1]);
		self::assertSame(LogicalOperator::OR, $query->getConditions()[1]->getOperator());
	}

	public function testFieldUsedOnlyInWhereIsNotASelection(): void
	{
		$query = query($this->makeRegistry()->getCollection('users'));
		$query->where(x()->eq($query->name, 'Ada'));

		self::assertCount(0, $query->getSelections());
	}

	public function testWhereRejectsEmptyCalls(): void
	{
		$this->expectException(InvalidArgumentException::class);
		query($this->makeRegistry()->getCollection('users'))->where();
	}

	public function testGroupByAppendsExpressionsPreservesIdentityAndNormalizesSubqueries(): void
	{
		$registry = $this->makeRegistry();
		$users = query($registry->getCollection('users'));
		$postCount = query($registry->getCollection('posts'), fn (SelectQuery $query) => $query->select($query->id->count()));
		$normalized = $users->name->upper();
		$users->select($normalized->as('normalized_name'));

		$returned = $users
			->groupBy($users->status, $users->get('normalized_name'))
			->groupBy($postCount);

		$groups = $users->getGroups();

		self::assertSame($users, $returned);
		self::assertCount(3, $groups);
		self::assertSame($users->status, $groups[0]);
		self::assertSame($normalized, $groups[1]);
		self::assertInstanceOf(SubqueryExpression::class, $groups[2]);
		self::assertSame($postCount, $groups[2]->getQuery());
	}

	public function testGroupByRejectsEmptyCalls(): void
	{
		$this->expectException(InvalidArgumentException::class);
		query($this->makeRegistry()->getCollection('users'))->groupBy();
	}

	public function testHavingAppendsConditionsPreservesOrderAndSupportsNamedAggregatesWithoutGroups(): void
	{
		$query = query($this->makeRegistry()->getCollection('users'));
		$total = $query->id->count();
		$query->select($total->as('total'));

		$first = x()->gt($query->get('total'), 10);
		$second = x()->or(
			x()->lt($query->tax->sum(), 1000),
			x()->gt($query->subtotal->sum(), 5),
		);

		$returned = $query->having($first)->having($second);

		self::assertSame($query, $returned);
		self::assertSame([$first, $second], $query->getHavingConditions());
		self::assertInstanceOf(LogicalCondition::class, $query->getHavingConditions()[1]);
		self::assertSame(LogicalOperator::OR, $query->getHavingConditions()[1]->getOperator());
	}

	public function testHavingRejectsEmptyCalls(): void
	{
		$this->expectException(InvalidArgumentException::class);
		query($this->makeRegistry()->getCollection('users'))->having();
	}

	public function testSortNodesRetainExpressionAndDirectionAcrossFluentAndFactoryForms(): void
	{
		$registry = $this->makeRegistry();
		$users = query($registry->getCollection('users'));
		$posts = query($registry->getCollection('posts'), fn (SelectQuery $query) => $query->select($query->id));
		$expression = $users->name->upper();

		$ascending = $expression->asc();
		$descending = $expression->desc();
		$factoryAscending = x()->asc($expression);
		$factoryDescending = x()->desc($posts);

		self::assertSame('asc', SortDirection::ASC->value);
		self::assertSame('desc', SortDirection::DESC->value);
		self::assertInstanceOf(Sort::class, $ascending);
		self::assertSame($expression, $ascending->getExpression());
		self::assertSame(SortDirection::ASC, $ascending->getDirection());
		self::assertSame($expression, $descending->getExpression());
		self::assertSame(SortDirection::DESC, $descending->getDirection());
		self::assertSame($ascending->getExpression(), $factoryAscending->getExpression());
		self::assertSame($ascending->getDirection(), $factoryAscending->getDirection());
		self::assertInstanceOf(SubqueryExpression::class, $factoryDescending->getExpression());
		self::assertSame($posts, $factoryDescending->getExpression()->getQuery());
	}

	public function testOrderByAppendsSortsPreservesPrecedenceAndSupportsNamedExpressions(): void
	{
		$query = query($this->makeRegistry()->getCollection('users'));
		$title = $query->name->upper();
		$query->select($title->as('title'));

		$first = $query->get('title')->asc();
		$second = $query->id->desc();
		$third = $query->subtotal->sum()->asc();

		$returned = $query->orderBy($first, $second)->orderBy($third);

		self::assertSame($query, $returned);
		self::assertSame([$first, $second, $third], $query->getSorts());
		self::assertSame($title, $query->getSorts()[0]->getExpression());
		self::assertSame(SortDirection::DESC, $query->getSorts()[1]->getDirection());
		self::assertInstanceOf(AggregateExpression::class, $query->getSorts()[2]->getExpression());
	}

	public function testOrderByRejectsEmptyCalls(): void
	{
		$this->expectException(InvalidArgumentException::class);
		query($this->makeRegistry()->getCollection('users'))->orderBy();
	}

	public function testPaginationDefaultsSupportZeroClearingAndLastCallWins(): void
	{
		$query = query($this->makeRegistry()->getCollection('users'));

		self::assertNull($query->getLimit());
		self::assertNull($query->getOffset());
		self::assertSame($query, $query->limit(0));
		self::assertSame(0, $query->getLimit());
		self::assertSame($query, $query->offset(50));
		self::assertSame(50, $query->getOffset());

		$query->limit(25)->offset(0);
		self::assertSame(25, $query->getLimit());
		self::assertSame(0, $query->getOffset());

		$query->limit(null)->offset(null);
		self::assertNull($query->getLimit());
		self::assertNull($query->getOffset());
	}

	public function testPaginationRejectsNegativeValues(): void
	{
		$query = query($this->makeRegistry()->getCollection('users'));

		try {
			$query->limit(-1);
			self::fail('Expected negative limit rejection.');
		} catch (InvalidArgumentException $exception) {
			self::assertSame('SelectQuery::limit() requires a non-negative integer or null.', $exception->getMessage());
		}

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('SelectQuery::offset() requires a non-negative integer or null.');
		$query->offset(-1);
	}

	public function testExpressionFactoryCoversLiteralsComparisonsLogicalNodesAndNullNormalization(): void
	{
		$query = query($this->makeRegistry()->getCollection('users'));
		$factory = x();

		self::assertInstanceOf(ExpressionFactory::class, $factory);
		self::assertSame($factory, x());

		$literal = $factory->literal(['ok' => true]);
		$eqLiteral = $factory->eq($query->active, true);
		$eqExpression = $factory->eq($query->updatedAt, $query->createdAt);
		$neqLiteral = $factory->neq($query->title, 'Ada');
		$gt = $factory->gt($query->id, 10);
		$gte = $factory->gte($query->id, 11);
		$lt = $factory->lt($query->id, 12);
		$lte = $factory->lte($query->id, 13);
		$and = $factory->and($eqLiteral);
		$or = $factory->or($eqLiteral, $neqLiteral);
		$not = $factory->not($eqLiteral);
		$isNull = $factory->isNull($query->deletedAt);
		$isNotNull = $factory->isNotNull($query->deletedAt);
		$eqNull = $factory->eq($query->deletedAt, null);
		$neqNull = $factory->neq($query->deletedAt, null);

		self::assertInstanceOf(LiteralExpression::class, $literal);
		self::assertSame(['ok' => true], $literal->getValue());
		self::assertComparison($eqLiteral, ComparisonOperator::EQ, $query->active, true);
		self::assertInstanceOf(ComparisonCondition::class, $eqExpression);
		self::assertSame($query->updatedAt, $eqExpression->getLeft());
		self::assertSame($query->createdAt, $eqExpression->getRight());
		self::assertComparison($neqLiteral, ComparisonOperator::NEQ, $query->title, 'Ada');
		self::assertComparison($gt, ComparisonOperator::GT, $query->id, 10);
		self::assertComparison($gte, ComparisonOperator::GTE, $query->id, 11);
		self::assertComparison($lt, ComparisonOperator::LT, $query->id, 12);
		self::assertComparison($lte, ComparisonOperator::LTE, $query->id, 13);
		self::assertInstanceOf(LogicalCondition::class, $and);
		self::assertSame(LogicalOperator::AND, $and->getOperator());
		self::assertSame([$eqLiteral], $and->getConditions());
		self::assertInstanceOf(LogicalCondition::class, $or);
		self::assertSame(LogicalOperator::OR, $or->getOperator());
		self::assertSame([$eqLiteral, $neqLiteral], $or->getConditions());
		self::assertInstanceOf(NotCondition::class, $not);
		self::assertSame($eqLiteral, $not->getCondition());
		self::assertNullCondition($isNull, NullOperator::IS_NULL, $query->deletedAt);
		self::assertNullCondition($isNotNull, NullOperator::IS_NOT_NULL, $query->deletedAt);
		self::assertNullCondition($eqNull, NullOperator::IS_NULL, $query->deletedAt);
		self::assertNullCondition($neqNull, NullOperator::IS_NOT_NULL, $query->deletedAt);
	}

	public function testFactoryRejectsInvalidLogicalGroupsAndOrderedNullComparisons(): void
	{
		$query = query($this->makeRegistry()->getCollection('users'));
		$factory = x();

		try {
			$factory->and();
			self::fail('Expected empty logical group rejection.');
		} catch (InvalidArgumentException) {
			self::assertTrue(true);
		}

		try {
			$factory->or();
			self::fail('Expected empty logical group rejection.');
		} catch (InvalidArgumentException) {
			self::assertTrue(true);
		}

		foreach (['gt', 'gte', 'lt', 'lte'] as $method) {
			try {
				$factory->{$method}($query->id, null);
				self::fail(sprintf('Expected %s() null rejection.', $method));
			} catch (InvalidArgumentException) {
				self::assertTrue(true);
			}
		}
	}

	public function testAliasingDoesNotMutateDefinitionsAndAliasedExpressionsCannotBeRealiasedOrCompared(): void
	{
		$registry = $this->makeRegistry();
		$users = $registry->getCollection('users');
		$before = $registry->all();
		$query = query($users);
		$alias = $query->email->as(' contact_email ');

		self::assertInstanceOf(AliasedExpression::class, $alias);
		self::assertSame('contact_email', $alias->getAlias());
		self::assertSame($query->email, $alias->getExpression());
		self::assertSame('mail_address', $users->getField('email')->getAlias());
		self::assertSame($before, $registry->all());

		$this->expectException(Error::class);
		$alias->as('again');
	}

	public function testAliasedExpressionsAreRejectedAsComparisonOperandsAndEmptyAliasesFail(): void
	{
		$query = query($this->makeRegistry()->getCollection('users'));

		$this->expectException(InvalidArgumentException::class);
		$query->id->as('   ');
	}

	public function testAliasedExpressionsAreRejectedAsComparisonOperands(): void
	{
		$query = query($this->makeRegistry()->getCollection('users'));

		$this->expectException(InvalidArgumentException::class);
		x()->eq($query->id, $query->title->as('headline'));
	}

	public function testAliasedExpressionsAreStructurallySelectionOnly(): void
	{
		$query = query($this->makeRegistry()->getCollection('users'));
		$alias = $query->id->as('user_id');

		try {
			x()->eq($alias, 1);
			self::fail('Expected aliased left operand rejection.');
		} catch (TypeError) {
			self::assertTrue(true);
		}

		try {
			x()->isNull($alias);
			self::fail('Expected aliased null-check rejection.');
		} catch (TypeError) {
			self::assertTrue(true);
		}

		$this->expectException(TypeError::class);
		new ComparisonCondition($alias, ComparisonOperator::EQ, x()->literal(1));
	}

	public function testStarExpressionsAreQueryOwnedCachedAndCountable(): void
	{
		$registry = $this->makeRegistry();
		$users = query($registry->getCollection('users'));
		$posts = query($registry->getCollection('posts'));
		$userStar = $users->star();
		$factoryCount = x()->count($users->star());

		self::assertInstanceOf(StarExpression::class, $userStar);
		self::assertSame($userStar, $users->star());
		self::assertNotSame($userStar, $posts->star());
		self::assertSame($users, $userStar->getQuery());
		self::assertInstanceOf(AggregateExpression::class, $factoryCount);
		self::assertSame(AggregateFunction::COUNT, $factoryCount->getFunction());
		self::assertSame($userStar, $factoryCount->getExpression());
	}

	public function testStarIsNotSelectableAndOnlySupportsCount(): void
	{
		$query = query($this->makeRegistry()->getCollection('users'));

		try {
			$query->select($query->star());
			self::fail('Expected star selection to fail by type.');
		} catch (TypeError) {
			self::assertTrue(true);
		}

		try {
			x()->countDistinct($query->star());
			self::fail('Expected countDistinct(star) to fail by type.');
		} catch (TypeError) {
			self::assertTrue(true);
		}

		$this->expectException(TypeError::class);
		x()->sum($query->star());
	}

	public function testAggregateFluentAndFactoryFormsAreEquivalent(): void
	{
		$query = query($this->makeRegistry()->getCollection('posts'));

		$fieldCount = $query->id->count();
		$factoryCount = x()->count($query->id);
		$fieldCountDistinct = $query->userId->countDistinct();
		$factoryCountDistinct = x()->countDistinct($query->userId);
		$fieldSum = $query->amount->sum();
		$factorySum = x()->sum($query->amount);

		self::assertAggregateEquivalent($fieldCount, $factoryCount);
		self::assertAggregateEquivalent($fieldCountDistinct, $factoryCountDistinct);
		self::assertAggregateEquivalent($fieldSum, $factorySum);
		self::assertSame('total_amount', $query->amount->sum()->as(' total_amount ')->getAlias());
	}

	public function testAggregateNodesRetainExactInputsAndFunctions(): void
	{
		$query = query($this->makeRegistry()->getCollection('posts'));
		$starCount = $query->star()->count();
		$fieldCount = $query->id->count();
		$countDistinct = $query->userId->countDistinct();
		$sum = $query->amount->sum();

		self::assertSame($query->star(), $starCount->getExpression());
		self::assertSame($query->id, $fieldCount->getExpression());
		self::assertSame(AggregateFunction::COUNT_DISTINCT, $countDistinct->getFunction());
		self::assertSame(AggregateFunction::SUM, $sum->getFunction());
	}

	public function testAggregateExpressionsCannotBeAggregatedAgain(): void
	{
		$aggregate = query($this->makeRegistry()->getCollection('posts'))->amount->sum();

		$this->expectException(Error::class);
		$aggregate->sum();
	}

	public function testFactoryRejectsNestedAggregateInputs(): void
	{
		$query = query($this->makeRegistry()->getCollection('posts'));

		try {
			x()->sum($query->amount->sum());
			self::fail('Expected nested SUM aggregate rejection.');
		} catch (InvalidArgumentException) {
			self::assertTrue(true);
		}

		try {
			x()->count($query->amount->count());
			self::fail('Expected nested COUNT aggregate rejection.');
		} catch (InvalidArgumentException) {
			self::assertTrue(true);
		}

		$this->expectException(InvalidArgumentException::class);
		x()->countDistinct($query->amount->sum());
	}

	public function testAggregateExpressionConstructorRejectsNonCountStarOperands(): void
	{
		$query = query($this->makeRegistry()->getCollection('posts'));

		$this->expectException(InvalidArgumentException::class);
		new AggregateExpression(AggregateFunction::SUM, $query->star());
	}

	public function testAggregateExpressionConstructorRejectsNestedAggregateOperands(): void
	{
		$query = query($this->makeRegistry()->getCollection('posts'));

		$this->expectException(InvalidArgumentException::class);
		new AggregateExpression(AggregateFunction::SUM, $query->amount->sum());
	}

	public function testAggregateExpressionConstructorRejectsAggregateContainingValueOperations(): void
	{
		$query = query($this->makeRegistry()->getCollection('users'));

		$this->expectException(InvalidArgumentException::class);
		new AggregateExpression(
			AggregateFunction::SUM,
			x()->add(
				$query->subtotal->sum(),
				$query->tax,
			),
		);
	}

	public function testValueOperationEnumAndConstructorArityRulesAreEnforced(): void
	{
		self::assertSame('upper', ValueOperation::UPPER->value);
		self::assertSame('lower', ValueOperation::LOWER->value);
		self::assertSame('concat', ValueOperation::CONCAT->value);
		self::assertSame('coalesce', ValueOperation::COALESCE->value);
		self::assertSame('add', ValueOperation::ADD->value);

		$query = query($this->makeRegistry()->getCollection('users'));
		$operation = new ValueOperationExpression(ValueOperation::ADD, [$query->subtotal, $query->tax, $query->id]);

		self::assertSame(ValueOperation::ADD, $operation->getOperation());
		self::assertSame([$query->subtotal, $query->tax, $query->id], $operation->getArguments());

		foreach ([
			[ValueOperation::UPPER, []],
			[ValueOperation::UPPER, [$query->name, $query->email]],
			[ValueOperation::LOWER, []],
			[ValueOperation::CONCAT, [$query->name]],
			[ValueOperation::COALESCE, [$query->name]],
			[ValueOperation::ADD, [$query->subtotal]],
		] as [$operationName, $arguments]) {
			try {
				new ValueOperationExpression($operationName, $arguments);
				self::fail('Expected invalid constructor arity rejection.');
			} catch (InvalidArgumentException) {
				self::assertTrue(true);
			}
		}
	}

	public function testValueOperationConstructorRejectsNonValueExpressionArguments(): void
	{
		$query = query($this->makeRegistry()->getCollection('users'));

		foreach ([
			[$query->id, 10],
			[$query->name, $query->star()],
			[$query->name, x()->isNull($query->name)],
			[$query->name, $query->name->as('title')],
		] as $arguments) {
			try {
				new ValueOperationExpression(ValueOperation::ADD, $arguments);
				self::fail('Expected malformed value-operation constructor rejection.');
			} catch (InvalidArgumentException $exception) {
				self::assertSame('Value-operation arguments must be value expressions.', $exception->getMessage());
			}
		}
	}

	public function testValueOperationFactoryNormalizesArgumentsAndRejectsKnownNonValueNodes(): void
	{
		$registry = $this->makeRegistry();
		$users = query($registry->getCollection('users'));
		$posts = query($registry->getCollection('posts'), fn (SelectQuery $query) => $query->select($query->id));
		$valueObject = new stdClass();

		$concat = x()->concat($users->firstName, ' ', $users->lastName);
		$coalesce = x()->coalesce($users->preferredName, null, $valueObject);
		$add = x()->add($users->subtotal, 10, $posts);

		self::assertSame(ValueOperation::CONCAT, $concat->getOperation());
		self::assertSame([$users->firstName, x()->literal(' ')->getValue(), $users->lastName], [
			$concat->getArguments()[0],
			$concat->getArguments()[1] instanceof LiteralExpression ? $concat->getArguments()[1]->getValue() : null,
			$concat->getArguments()[2],
		]);
		self::assertInstanceOf(LiteralExpression::class, $coalesce->getArguments()[1]);
		self::assertNull($coalesce->getArguments()[1]->getValue());
		self::assertInstanceOf(LiteralExpression::class, $coalesce->getArguments()[2]);
		self::assertSame($valueObject, $coalesce->getArguments()[2]->getValue());
		self::assertInstanceOf(LiteralExpression::class, $add->getArguments()[1]);
		self::assertSame(10, $add->getArguments()[1]->getValue());
		self::assertInstanceOf(SubqueryExpression::class, $add->getArguments()[2]);
		self::assertSame($posts, $add->getArguments()[2]->getQuery());

		foreach ([
			fn () => x()->upper($users->name->as('title')),
			fn () => x()->concat($users->name, $users->star()),
			fn () => x()->coalesce($users->name, x()->isNull($users->name)),
			fn () => x()->eq($users->id, $users->star()),
			fn () => x()->eq($users->id, x()->eq($users->active, true)),
		] as $assertion) {
			try {
				$assertion();
				self::fail('Expected invalid operand rejection.');
			} catch (InvalidArgumentException) {
				self::assertTrue(true);
			}
		}
	}

	public function testFluentUpperAndLowerDelegateToFactorySemantics(): void
	{
		$query = query($this->makeRegistry()->getCollection('users'));

		self::assertValueOperationEquivalent($query->name->upper(), x()->upper($query->name));
		self::assertValueOperationEquivalent($query->email->lower(), x()->lower($query->email));
	}

	public function testValueOperationsSupportOrderingNestingAndSelectionComposition(): void
	{
		$registry = $this->makeRegistry();
		$users = query($registry->getCollection('users'));
		$posts = query($registry->getCollection('posts'), fn (SelectQuery $query) => $query->select($query->id));

		$nested = x()->coalesce(
			x()->concat($users->firstName, ' ', $users->lastName),
			x()->upper($posts),
			'Unknown',
		);

		self::assertSame(ValueOperation::COALESCE, $nested->getOperation());
		self::assertInstanceOf(ValueOperationExpression::class, $nested->getArguments()[0]);
		self::assertSame(ValueOperation::CONCAT, $nested->getArguments()[0]->getOperation());
		self::assertSame([$users->firstName, $users->lastName], [
			$nested->getArguments()[0]->getArguments()[0],
			$nested->getArguments()[0]->getArguments()[2],
		]);
		self::assertInstanceOf(ValueOperationExpression::class, $nested->getArguments()[1]);
		self::assertSame(ValueOperation::UPPER, $nested->getArguments()[1]->getOperation());
		self::assertInstanceOf(SubqueryExpression::class, $nested->getArguments()[1]->getArguments()[0]);
		self::assertInstanceOf(LiteralExpression::class, $nested->getArguments()[2]);
		self::assertSame('Unknown', $nested->getArguments()[2]->getValue());

		$selection = x()->add($users->subtotal, $users->tax)->sum()->as('total');
		self::assertSame('total', $selection->getAlias());
		self::assertInstanceOf(AggregateExpression::class, $selection->getExpression());
	}

	public function testAggregateCompositionSupportsValueOperationsWithoutReintroducingNestedAggregates(): void
	{
		$query = query($this->makeRegistry()->getCollection('users'));

		$sum = x()->add($query->subtotal, $query->tax)->sum();
		$factoryForm = x()->sum(x()->add($query->subtotal, $query->tax));
		$aggregateCombination = x()->add($query->subtotal->sum(), $query->tax->sum());
		$subqueryAggregate = query($this->makeRegistry()->getCollection('posts'), fn (SelectQuery $posts) => $posts->select($posts->amount->sum()));

		self::assertSame(AggregateFunction::SUM, $sum->getFunction());
		self::assertInstanceOf(ValueOperationExpression::class, $sum->getExpression());
		self::assertSame(AggregateFunction::SUM, $factoryForm->getFunction());
		self::assertInstanceOf(ValueOperationExpression::class, $factoryForm->getExpression());
		self::assertSame(ValueOperation::ADD, $aggregateCombination->getOperation());
		self::assertInstanceOf(AggregateExpression::class, $aggregateCombination->getArguments()[0]);
		self::assertInstanceOf(AggregateExpression::class, $aggregateCombination->getArguments()[1]);
		self::assertSame(AggregateFunction::SUM, x()->sum(x()->add($query->subtotal, $subqueryAggregate))->getFunction());

		foreach ([
			fn () => x()->add($query->subtotal->sum(), $query->tax)->sum(),
			fn () => x()->sum(x()->coalesce(x()->add($query->subtotal->sum(), $query->tax), 0)),
		] as $assertion) {
			try {
				$assertion();
				self::fail('Expected aggregate nesting rejection.');
			} catch (InvalidArgumentException) {
				self::assertTrue(true);
			}
		}
	}

	public function testSelectingAliasesRegistersUnderlyingExpressionsAndSupportsLookup(): void
	{
		$query = query($this->makeRegistry()->getCollection('users'));
		$expression = $query->name->upper();
		$alias = $expression->as(' title ');

		try {
			$query->get('title');
			self::fail('Expected lookup to fail before selection.');
		} catch (UnknownQueryExpressionException) {
			self::assertTrue(true);
		}

		$query->select($query->name, $alias);

		self::assertSame($expression, $query->get('title'));
		self::assertSame($expression, $query->get(' title '));
		self::assertSame($query->get('title'), $query->get('title'));
		self::assertFalse($query->getSelections()->getAll()[0]->getExpression() instanceof AliasedExpression);

		try {
			$query->get('   ');
			self::fail('Expected empty lookup rejection.');
		} catch (InvalidArgumentException) {
			self::assertTrue(true);
		}

		try {
			$query->get('missing');
			self::fail('Expected unknown lookup rejection.');
		} catch (UnknownQueryExpressionException $exception) {
			self::assertSame("Unknown query expression 'missing' on definition 'users'.", $exception->getMessage());
		}
	}

	public function testSelectRejectsDuplicateAliasesAndPreservesAtomicBehavior(): void
	{
		$query = query($this->makeRegistry()->getCollection('users'));
		$existing = $query->name->upper();

		$query->select($query->id, $existing->as('title'));
		$beforeSelections = $query->getSelections()->getAll();

		try {
			$query->select(
				$query->email->as('other'),
				$query->title->as('other'),
			);
			self::fail('Expected duplicate alias rejection within one select() call.');
		} catch (InvalidArgumentException) {
			self::assertSame($beforeSelections, $query->getSelections()->getAll());
			self::assertSame($existing, $query->get('title'));

			try {
				$query->get('other');
				self::fail('Expected failed batch alias to remain unregistered.');
			} catch (UnknownQueryExpressionException) {
				self::assertTrue(true);
			}
		}

		try {
			$query->select($query->email->as('title'));
			self::fail('Expected duplicate alias rejection across select() calls.');
		} catch (InvalidArgumentException) {
			self::assertSame($beforeSelections, $query->getSelections()->getAll());
		}

		$sameExpression = $query->name->upper();
		$query->select(
			$sameExpression->as('name_upper'),
			$sameExpression->as('Title'),
		);

		self::assertSame($sameExpression, $query->get('name_upper'));
		self::assertSame($sameExpression, $query->get('Title'));
		self::assertNotSame($query->get('Title'), $query->email);
	}

	public function testNamedExpressionsRemainQueryLocalAcrossNestedQueries(): void
	{
		$registry = $this->makeRegistry();
		$users = query($registry->getCollection('users'));
		$posts = query($registry->getCollection('posts'));
		$correlated = x()->upper($users->name);

		$users->select($correlated->as('title'));
		$posts->select($posts->id->as('post_id'));

		self::assertSame($correlated, $users->get('title'));

		try {
			$users->get('post_id');
			self::fail('Expected outer query to reject inner alias lookup.');
		} catch (UnknownQueryExpressionException) {
			self::assertTrue(true);
		}

		try {
			$posts->get('title');
			self::fail('Expected inner query to reject outer alias lookup.');
		} catch (UnknownQueryExpressionException) {
			self::assertTrue(true);
		}

		$posts->select(x()->concat($posts->id, '-', $users->id)->as('pair_key'));
		self::assertSame($users, $correlated->getArguments()[0]->getQuery());
		self::assertSame($posts, $posts->get('pair_key')->getArguments()[0]->getQuery());
	}

	public function testDirectSubquerySelectionAndQueryAliasingNormalizeToSubqueryExpressions(): void
	{
		$registry = $this->makeRegistry();
		$users = query($registry->getCollection('users'));
		$posts = query($registry->getCollection('posts'));

		$users->select($users->id, $posts);
		$aliased = $posts->as('post_count');
		$posts->select($posts->id->count());

		$selections = $users->getSelections()->getAll();

		self::assertCount(2, $selections);
		self::assertInstanceOf(SubqueryExpression::class, $selections[1]->getExpression());
		self::assertSame($posts, $selections[1]->getExpression()->getQuery());
		self::assertInstanceOf(AliasedExpression::class, $aliased);
		self::assertInstanceOf(SubqueryExpression::class, $aliased->getExpression());
		self::assertSame($posts, $aliased->getExpression()->getQuery());
		self::assertCount(1, $selections[1]->getExpression()->getQuery()->getSelections());
		self::assertFalse(is_a($posts, ValueExpressionInterface::class));
	}

	public function testRootSelectionKeysPreserveExistingFieldAndAliasConventions(): void
	{
		$query = query($this->makeRegistry()->getCollection('users'));
		$query->select($query->name, $query->email->as('mail'));

		self::assertSame(['name', 'mail'], array_map(
			static fn (SelectionItem $selection): string => $selection->getSelectionKey(),
			$query->getSelections()->getExplicit(),
		));
	}

	public function testExplicitJoinRejectsSourceFromAnotherQuery(): void
	{
		$registry = $this->makeRegistry();
		$users = query($registry->getCollection('users'));
		$other = query($registry->getCollection('users'));

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('belongs to a different SelectQuery');

		$users->join($registry->getCollection('posts'), source: $other);
	}

	public function testExplicitJoinCanonicalizesRelationSourceBeforeStorage(): void
	{
		$registry = $this->makeRegistry();
		$users = query($registry->getCollection('users'));
		$users->posts->getJoinedSource();

		$join = $users->join($registry->getCollection('posts'), source: $users->posts, name: 'post_copy');

		self::assertCount(2, $users->getJoins());
		self::assertSame('posts', $users->getJoins()[0]->getName());
		self::assertSame('post_copy', $users->getJoins()[1]->getName());
		self::assertSame($users->getJoins()[0], $join->getSource());
	}

	public function testExplicitJoinRejectsNameCreatedWhileResolvingRelationSource(): void
	{
		$registry = $this->makeRegistry();
		$users = query($registry->getCollection('users'));

		try {
			$users->join($registry->getCollection('posts'), source: $users->posts, name: 'posts');
			self::fail('Expected duplicate join-name rejection.');
		} catch (InvalidArgumentException $exception) {
			self::assertStringContainsString('Join name "posts" is already used by this query.', $exception->getMessage());
		}

		self::assertCount(1, $users->getJoins());
		self::assertSame('posts', $users->getJoins()[0]->getName());
	}

	public function testRelationLoaderRejectsAbstractAndArgumentfulClasses(): void
	{
		$registry = $this->makeRegistry();
		$users = query($registry->getCollection('users'));
		$users->getCollection()->getRelation('posts')?->loader(AbstractTestLoader::class);

		try {
			$users->posts->getLoader();
			self::fail('Expected abstract loader rejection.');
		} catch (InvalidArgumentException $exception) {
			self::assertStringContainsString('not instantiable', $exception->getMessage());
		}

		$usersWithCtor = query($registry->getCollection('users'));
		$usersWithCtor->getCollection()->getRelation('posts')?->loader(RequiredCtorLoader::class);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('constructor must not require arguments');
		$usersWithCtor->posts->getLoader();
	}

	public function testIterateRejectsStructuredRelationSelections(): void
	{
		$users = query($this->makeRegistry()->getCollection('users'));
		$users->select($users->posts);

		$this->expectException(RelationSelectionException::class);
		$this->expectExceptionMessage('iterate() is not supported');
		iterator_to_array($users->iterate(), false);
	}

	public function testLoadRuntimeDoesNotExposeHiddenActiveBranchMetadataApis(): void
	{
		self::assertFalse(method_exists(LoadRuntime::class, 'getParserFields'));
	}

	public function testBuiltInLoaderDefaultsMatchStructuredLoadingStrategies(): void
	{
		$registry = new Registry();
		$users = $registry->collection('users');
		$users->field('id', 'int');
		$users->primaryKey('id');
		$posts = $registry->collection('posts');
		$posts->field('id', 'int');
		$posts->field('userId', 'int');
		$posts->primaryKey('id');
		$users->hasMany('posts', 'posts')->innerKey('id')->outerKey('userId')->end();
		$posts->belongsTo('author', 'users')->innerKey('userId')->outerKey('id')->end();

		$userQuery = query($registry->getCollection('users'));
		$postQuery = query($registry->getCollection('posts'));

		self::assertSame(LoadStrategy::SEPARATE_QUERY, $userQuery->posts->getLoader()->getDefaultLoadStrategy());
		self::assertSame(LoadStrategy::JOIN, $postQuery->author->getLoader()->getDefaultLoadStrategy());
	}

	public function testCorrelatedReferencesRetainTheirOwningQueries(): void
	{
		$registry = $this->makeRegistry();
		$users = query($registry->getCollection('users'));
		$posts = query($registry->getCollection('posts'));
		$condition = x()->eq($posts->userId, $users->id);

		self::assertSame($posts, $condition->getLeft()->getQuery());
		self::assertSame($users, $condition->getRight()->getQuery());
	}

	public function testScalarComparisonsNormalizeSelectQueriesOnEitherSide(): void
	{
		$registry = $this->makeRegistry();
		$users = query($registry->getCollection('users'));
		$posts = query($registry->getCollection('posts'), fn (SelectQuery $query) => $query->select($query->id));
		$right = x()->eq($users->lastPostId, $posts);
		$left = x()->gt($posts, 10);
		$nullLeft = x()->eq($posts, null);

		self::assertInstanceOf(SubqueryExpression::class, $right->getRight());
		self::assertSame($posts, $right->getRight()->getQuery());
		self::assertInstanceOf(SubqueryExpression::class, $left->getLeft());
		self::assertSame($posts, $left->getLeft()->getQuery());
		self::assertInstanceOf(NullCondition::class, $nullLeft);
		self::assertInstanceOf(SubqueryExpression::class, $nullLeft->getExpression());
	}

	public function testExistsAndNotExistsRetainTheExactNestedQuery(): void
	{
		$posts = query($this->makeRegistry()->getCollection('posts'));
		$exists = x()->exists($posts);
		$notExists = x()->notExists($posts);

		self::assertInstanceOf(ExistsCondition::class, $exists);
		self::assertSame($posts, $exists->getQuery());
		self::assertFalse($exists->isNegated());
		self::assertSame($posts, $notExists->getQuery());
		self::assertTrue($notExists->isNegated());
		self::assertSame([], $posts->getSelections()->getAll());
	}

	public function testComputedAndAliasedExpressionsAreStoredInsideSelections(): void
	{
		$query = query($this->makeRegistry()->getCollection('users'));
		$computed = $query->name->upper();
		$aliased = $computed->as('upper_name');

		$query->select($computed, $aliased);
		$selections = $query->getSelections()->getAll();

		self::assertSame($computed, $selections[0]->getExpression());
		self::assertSame($aliased, $selections[1]->getExpression());
	}

	public function testSelectionListInspectionApisExposeExplicitImplicitReasonAndFilteredEntries(): void
	{
		$query = query($this->makeRegistry()->getCollection('users'));
		$query
			->select($query->name, $query->email->as('mail'))
			->require($query->id, SelectionReason::RELATION)
			->require($query->id, 'result-grouping-key');

		$selections = $query->getSelections();

		self::assertInstanceOf(SelectionList::class, $selections);
		self::assertCount(2, $selections->getExplicit());
		self::assertCount(1, $selections->getImplicit());
		self::assertCount(1, $selections->getByReason(SelectionReason::RELATION));
		self::assertCount(1, $selections->filter(static fn (SelectionItem $selection): bool => $selection->hasReason('result-grouping-key'))->getAll());
	}

	public function testSelectionListFilterReturnsANewListAndPreservesOrder(): void
	{
		$query = query($this->makeRegistry()->getCollection('users'));
		$query
			->select($query->name, $query->email->as('mail'))
			->require($query->id, SelectionReason::RELATION);

		$filtered = $query->getSelections()->filter(
			static fn (SelectionItem $selection): bool => $selection->isExplicit(),
		);

		self::assertNotSame($query->getSelections(), $filtered);
		self::assertSame(
			[$query->name, $query->email->as('mail')->getExpression()],
			array_map(
				static fn (SelectionItem $selection): mixed => $selection->getExpression() instanceof AliasedExpression
					? $selection->getExpression()->getExpression()
					: $selection->getExpression(),
				$filtered->getAll(),
			),
		);
		self::assertCount(3, $query->getSelections()->getAll());
	}

	public function testSelectionListFilterSupportsOrLogicThroughCallbacks(): void
	{
		$query = query($this->makeRegistry()->getCollection('users'));
		$query
			->select($query->name)
			->require($query->id, SelectionReason::RELATION)
			->require($query->title, SelectionReason::INTERNAL);

		$filtered = $query->getSelections()->filter(
			static fn (SelectionItem $selection): bool => $selection->hasReason(SelectionReason::RELATION)
				|| $selection->hasReason(SelectionReason::INTERNAL),
		);

		self::assertSame([$query->id, $query->title], array_map(
			static fn (SelectionItem $selection): mixed => $selection->getExpression(),
			$filtered->getAll(),
		));
	}

	public function testSelectionListAddMergesReasonsAndReturnsTheUpdatedItem(): void
	{
		$query = query($this->makeRegistry()->getCollection('users'));
		$list = new SelectionList();

		$first = $list->add($query->id, SelectionReason::RELATION);
		$second = $list->add($query->id, [SelectionReason::INTERNAL, SelectionReason::RELATION]);

		self::assertNotSame($first, $second);
		self::assertSame([SelectionReason::RELATION, SelectionReason::INTERNAL], $second->getReasons());
		self::assertSame($second, $list->getAll()[0]);
	}

	public function testSelectionListParserItemsExcludeExplicitOnlyEntries(): void
	{
		$query = query($this->makeRegistry()->getCollection('users'));
		$list = new SelectionList();

		$list->add($query->id, SelectionReason::EXPLICIT);
		$list->add($query->name, SelectionReason::PUBLIC);

		self::assertSame([$query->name], array_map(
			static fn (SelectionItem $selection): mixed => $selection->getExpression(),
			$list->getParserItems(),
		));
	}

	public function testSelectionListParserItemsIncludePublicRequiredRelationAndIdentityEntries(): void
	{
		$query = query($this->makeRegistry()->getCollection('users'));
		$list = new SelectionList();

		$list->add($query->name, SelectionReason::PUBLIC);
		$list->add($query->email, SelectionReason::REQUIRED);
		$list->add($query->id, SelectionReason::RELATION);
		$list->add($query->title, SelectionReason::IDENTITY);
		$list->add($query->active, SelectionReason::INTERNAL);

		self::assertSame(
			[$query->name, $query->email, $query->id, $query->title],
			array_map(static fn (SelectionItem $selection): mixed => $selection->getExpression(), $list->getParserItems()),
		);
	}

	public function testSelectionListPublicItemsFollowPublicReasonInsertionOrder(): void
	{
		$query = query($this->makeRegistry()->getCollection('users'));
		$list = new SelectionList();

		$list->add($query->id, SelectionReason::REQUIRED);
		$list->add($query->name, SelectionReason::PUBLIC);
		$list->add($query->id, SelectionReason::PUBLIC);

		self::assertSame(
			[$query->name, $query->id],
			array_map(static fn (SelectionItem $selection): mixed => $selection->getExpression(), $list->getPublicItems()),
		);
	}

	public function testSelectionListIdentityItemsFollowIdentityReasonInsertionOrder(): void
	{
		$query = query($this->makeRegistry()->getCollection('users'));
		$list = new SelectionList();

		$list->add($query->name, SelectionReason::REQUIRED);
		$list->add($query->id, SelectionReason::IDENTITY);
		$list->add($query->title, SelectionReason::IDENTITY);

		self::assertSame(
			[$query->id, $query->title],
			array_map(static fn (SelectionItem $selection): mixed => $selection->getExpression(), $list->getIdentityItems()),
		);
	}

	public function testSelectionListFilterPreservesReasonDerivedViews(): void
	{
		$query = query($this->makeRegistry()->getCollection('users'));
		$list = new SelectionList();

		$list->add($query->id, SelectionReason::REQUIRED);
		$list->add($query->name, SelectionReason::PUBLIC);
		$list->add($query->id, SelectionReason::PUBLIC);
		$list->add($query->title, SelectionReason::IDENTITY);

		$filtered = $list->filter(static fn (SelectionItem $selection): bool => $selection->getExpression() !== $query->name);

		self::assertSame(
			[$query->id],
			array_map(static fn (SelectionItem $selection): mixed => $selection->getExpression(), $filtered->getPublicItems()),
		);
		self::assertSame(
			[$query->id, $query->title],
			array_map(static fn (SelectionItem $selection): mixed => $selection->getExpression(), $filtered->getParserItems()),
		);
		self::assertSame(
			[$query->title],
			array_map(static fn (SelectionItem $selection): mixed => $selection->getExpression(), $filtered->getIdentityItems()),
		);
	}

	public function testSelectionListAddDoesNotDuplicateReasonOrderEntries(): void
	{
		$query = query($this->makeRegistry()->getCollection('users'));
		$list = new SelectionList();

		$list->add($query->id, SelectionReason::PUBLIC);
		$list->add($query->id, SelectionReason::PUBLIC);

		self::assertSame(
			[$query->id],
			array_map(static fn (SelectionItem $selection): mixed => $selection->getExpression(), $list->getPublicItems()),
		);
	}

	public function testSelectionItemDoesNotExposeRoleSpecificApis(): void
	{
		foreach (['asPublic', 'asIdentity', 'parse', 'includeInParser', 'asParserKey', 'asRowAlias'] as $method) {
			self::assertFalse(method_exists(SelectionItem::class, $method), $method);
		}
	}

	public function testRequireCreatesAnImplicitSelectionAndAccumulatesUniqueReasons(): void
	{
		$query = query($this->makeRegistry()->getCollection('users'));
		$query
			->require($query->id, ' relation-key ')
			->require($query->id, 'result-grouping-key')
			->require($query->id, 'relation-key');

		$selections = $query->getSelections()->getAll();

		self::assertCount(1, $selections);
		self::assertTrue($selections[0]->isImplicit());
		self::assertSame(['relation-key', 'result-grouping-key'], $selections[0]->getReasons());
	}

	public function testRequireAfterSelectAnnotatesTheExistingExplicitEntry(): void
	{
		$query = query($this->makeRegistry()->getCollection('users'));
		$query->select($query->id)->require($query->id, 'relation-key');

		$selection = $query->getSelections()->getAll()[0];

		self::assertTrue($selection->isExplicit());
		self::assertTrue($selection->hasReason('relation-key'));
	}

	public function testSelectAfterRequirePromotesTheExistingEntryAndAnotherExplicitSelectMayStillDuplicateIt(): void
	{
		$query = query($this->makeRegistry()->getCollection('users'));
		$query->require($query->id, 'relation-key');
		$query->select($query->id);

		$selections = $query->getSelections()->getAll();
		self::assertCount(1, $selections);
		self::assertTrue($selections[0]->isExplicit());
		self::assertSame(['relation-key'], $selections[0]->getReasons());

		$query->select($query->id);
		$selections = $query->getSelections()->getAll();

		self::assertCount(2, $selections);
		self::assertSame($query->id, $selections[0]->getExpression());
		self::assertSame($query->id, $selections[1]->getExpression());
	}

	public function testAliasedAndUnaliasedOccurrencesRemainDistinctAcrossSelectAndRequire(): void
	{
		$query = query($this->makeRegistry()->getCollection('users'));
		$query
			->select($query->id->as('user_id'))
			->require($query->id, 'relation-key');

		$selections = $query->getSelections()->getAll();

		self::assertCount(2, $selections);
		self::assertInstanceOf(AliasedExpression::class, $selections[0]->getExpression());
		self::assertSame($query->id, $selections[1]->getExpression());
		self::assertTrue($selections[1]->hasReason('relation-key'));
	}

	public function testInAndNotInSupportLiteralListsExpressionsAndSubqueries(): void
	{
		$registry = $this->makeRegistry();
		$users = query($registry->getCollection('users'));
		$posts = query($registry->getCollection('posts'), fn (SelectQuery $query) => $query->select($query->userId));
		$literalIn = x()->in($users->status, ['active', $users->title]);
		$subqueryIn = x()->notIn($users->id, $posts);

		self::assertInstanceOf(InCondition::class, $literalIn);
		self::assertInstanceOf(LiteralExpression::class, $literalIn->getSet()[0]);
		self::assertSame('active', $literalIn->getSet()[0]->getValue());
		self::assertSame($users->title, $literalIn->getSet()[1]);
		self::assertInstanceOf(SubqueryExpression::class, $subqueryIn->getSet());
		self::assertSame($posts, $subqueryIn->getSet()->getQuery());
		self::assertTrue($subqueryIn->isNegated());
	}

	public function testInRejectsEmptyNullAliasAndStarInputs(): void
	{
		$query = query($this->makeRegistry()->getCollection('users'));

		$this->expectException(InvalidArgumentException::class);
		x()->in($query->status, []);
	}

	public function testInRejectsNullEntries(): void
	{
		$query = query($this->makeRegistry()->getCollection('users'));

		$this->expectException(InvalidArgumentException::class);
		x()->in($query->status, ['active', null]);
	}

	public function testInRejectsAliasedSetEntries(): void
	{
		$query = query($this->makeRegistry()->getCollection('users'));

		$this->expectException(InvalidArgumentException::class);
		x()->in($query->status, [$query->title->as('headline')]);
	}

	public function testInRejectsStarSetEntriesAndAliasedExpressions(): void
	{
		$query = query($this->makeRegistry()->getCollection('users'));

		try {
			x()->in($query->status, [$query->star()]);
			self::fail('Expected star IN entry rejection.');
		} catch (InvalidArgumentException) {
			self::assertTrue(true);
		}

		$this->expectException(TypeError::class);
		x()->in($query->status->as('user_status'), ['active']);
	}

	public function testInRejectsSubqueriesInsideLiteralLists(): void
	{
		$registry = $this->makeRegistry();
		$users = query($registry->getCollection('users'));
		$posts = query($registry->getCollection('posts'), fn (SelectQuery $query) => $query->select($query->userId));

		try {
			x()->in($users->id, [$posts]);
			self::fail('Expected single subquery list entry rejection.');
		} catch (InvalidArgumentException) {
			self::assertTrue(true);
		}

		try {
			x()->in($users->id, [1, $posts]);
			self::fail('Expected mixed literal/subquery list rejection.');
		} catch (InvalidArgumentException) {
			self::assertTrue(true);
		}

		$this->expectException(InvalidArgumentException::class);
		x()->in($users->id, [new SubqueryExpression($posts)]);
	}

	public function testInConditionConstructorRejectsInvalidArrayMembers(): void
	{
		$registry = $this->makeRegistry();
		$users = query($registry->getCollection('users'));
		$posts = query($registry->getCollection('posts'));

		try {
			new InCondition($users->id, [$users->star()]);
			self::fail('Expected star list member constructor rejection.');
		} catch (InvalidArgumentException) {
			self::assertTrue(true);
		}

		try {
			new InCondition($users->id, [new SubqueryExpression($posts)]);
			self::fail('Expected subquery list member constructor rejection.');
		} catch (InvalidArgumentException) {
			self::assertTrue(true);
		}

		$this->expectException(InvalidArgumentException::class);
		new InCondition($users->id, []);
	}

	private static function assertComparison(
		ComparisonCondition $condition,
		ComparisonOperator $operator,
		FieldRef $left,
		mixed $rightLiteral,
	): void {
		self::assertSame($left, $condition->getLeft());
		self::assertSame($operator, $condition->getOperator());
		self::assertInstanceOf(LiteralExpression::class, $condition->getRight());
		self::assertSame($rightLiteral, $condition->getRight()->getValue());
	}

	private static function assertNullCondition(object $condition, NullOperator $operator, ValueExpressionInterface $expression): void
	{
		self::assertInstanceOf(NullCondition::class, $condition);
		self::assertSame($operator, $condition->getOperator());
		self::assertSame($expression, $condition->getExpression());
	}

	private static function assertAggregateEquivalent(AggregateExpression $left, AggregateExpression $right): void
	{
		self::assertSame($left->getFunction(), $right->getFunction());
		self::assertSame($left->getExpression(), $right->getExpression());
	}

	private static function assertValueOperationEquivalent(ValueOperationExpression $left, ValueOperationExpression $right): void
	{
		self::assertSame($left->getOperation(), $right->getOperation());
		self::assertSame($left->getArguments(), $right->getArguments());
	}

	private function makeRegistry(): Registry
	{
		$registry = new Registry();
		$users = $registry->collection('users');
		$users->field('id', 'int');
		$users->field('name', 'string');
		$users->field('firstName', 'string');
		$users->field('lastName', 'string');
		$users->field('preferredName', 'string');
		$users->field('title', 'string');
		$users->field('email', 'string')->alias('mail_address');
		$users->field('active', 'bool');
		$users->field('status', 'string');
		$users->field('lastPostId', 'int');
		$users->field('subtotal', 'float');
		$users->field('tax', 'float');
		$users->field('deletedAt', 'datetime');
		$users->field('createdAt', 'datetime');
		$users->field('updatedAt', 'datetime');
		$users->relation('posts', CustomRelation::class)
			->collection('posts')
			->innerKey('id')
			->outerKey('userId');

		$posts = $registry->collection('posts');
		$posts->field('id', 'int');
		$posts->field('userId', 'int');
		$posts->field('amount', 'float');
		$posts->field('published', 'bool');

		$view = $registry->view('user_summary');
		$view->source($users);
		$view->field('id', 'int');
		$view->field('title', 'string');

		return $registry;
	}
}
