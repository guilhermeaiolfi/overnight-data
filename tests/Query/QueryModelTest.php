<?php

declare(strict_types=1);

namespace Tests\ON\Data\Query;

use DateTimeImmutable;
use Error;
use InvalidArgumentException;
use ON\Data\Definition\Collection\CollectionInterface;
use ON\Data\Definition\Registry;
use ON\Data\Definition\View\ViewDefinitionInterface;
use ON\Data\Query\Condition\ComparisonCondition;
use ON\Data\Query\Condition\ComparisonOperator;
use ON\Data\Query\Condition\ExistsCondition;
use ON\Data\Query\Condition\InCondition;
use ON\Data\Query\Condition\LogicalCondition;
use ON\Data\Query\Condition\LogicalOperator;
use ON\Data\Query\Condition\NotCondition;
use ON\Data\Query\Condition\NullCondition;
use ON\Data\Query\Condition\NullOperator;
use ON\Data\Query\Exception\UnknownQueryFieldException;
use ON\Data\Query\Expression\AggregateExpression;
use ON\Data\Query\Expression\AggregateFunction;
use ON\Data\Query\Expression\AliasedExpression;
use ON\Data\Query\Expression\FieldRef;
use ON\Data\Query\Expression\LiteralExpression;
use ON\Data\Query\Expression\StarExpression;
use ON\Data\Query\Expression\SubqueryExpression;
use ON\Data\Query\Expression\ValueExpressionInterface;
use ON\Data\Query\ExpressionFactory;
use function ON\Data\Query\query;
use ON\Data\Query\SelectQuery;
use function ON\Data\Query\x;
use PHPUnit\Framework\TestCase;
use Tests\ON\Data\Fixture\CustomRelation;
use TypeError;

final class QueryModelTest extends TestCase
{
	public function testQueryCreationSupportsCollectionsViewsAndCallbacks(): void
	{
		$registry = $this->makeRegistry();
		$collection = $registry->getCollection('users');
		$view = $registry->getView('user_summary');

		self::assertInstanceOf(CollectionInterface::class, $collection);
		self::assertInstanceOf(ViewDefinitionInterface::class, $view);

		$collectionQuery = query($collection);
		$viewQuery = query($view);

		self::assertInstanceOf(SelectQuery::class, $collectionQuery);
		self::assertSame($collection, $collectionQuery->getSource());
		self::assertSame($view, $viewQuery->getSource());

		$callbackResult = query($collection, function (SelectQuery $query): string {
			$query->select($query->id);

			return 'ignored';
		});

		$voidResult = query($collection, function (SelectQuery $query): void {
			$query->where(x()->eq($query->active, true));
		});

		$arrowResult = query($collection, fn (SelectQuery $query) => $query->select($query->title));

		self::assertSame($collection, $callbackResult->getSource());
		self::assertCount(1, $callbackResult->getSelections());
		self::assertSame($collection, $voidResult->getSource());
		self::assertCount(1, $voidResult->getConditions());
		self::assertSame($collection, $arrowResult->getSource());
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
		} catch (UnknownQueryFieldException $exception) {
			self::assertSame("Unknown query field 'missing' on definition 'users'.", $exception->getMessage());
		}

		$this->expectException(UnknownQueryFieldException::class);
		$query->field('posts');
	}

	public function testSelectAppendsExpressionsPreservesOrderAndExposesAliases(): void
	{
		$query = query($this->makeRegistry()->getCollection('users'));
		$firstReturn = $query->select($query->id);
		$secondReturn = $query->select($query->title->as(' headline '));
		$selections = $query->getSelections();

		self::assertSame($query, $firstReturn);
		self::assertSame($query, $secondReturn);
		self::assertCount(2, $selections);
		self::assertSame($query->id, $selections[0]);
		self::assertInstanceOf(AliasedExpression::class, $selections[1]);
		self::assertSame('headline', $selections[1]->getAlias());
		self::assertSame($query->title, $selections[1]->getExpression());
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

	public function testWhereRejectsEmptyCalls(): void
	{
		$this->expectException(InvalidArgumentException::class);
		query($this->makeRegistry()->getCollection('users'))->where();
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

	public function testAggregateExpressionsAreNotAggregateableInPhaseTwo(): void
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

	public function testDirectSubquerySelectionAndQueryAliasingNormalizeToSubqueryExpressions(): void
	{
		$registry = $this->makeRegistry();
		$users = query($registry->getCollection('users'));
		$posts = query($registry->getCollection('posts'));

		$users->select($users->id, $posts);
		$aliased = $posts->as('post_count');
		$posts->select($posts->id->count());

		$selections = $users->getSelections();

		self::assertCount(2, $selections);
		self::assertInstanceOf(SubqueryExpression::class, $selections[1]);
		self::assertSame($posts, $selections[1]->getQuery());
		self::assertInstanceOf(AliasedExpression::class, $aliased);
		self::assertInstanceOf(SubqueryExpression::class, $aliased->getExpression());
		self::assertSame($posts, $aliased->getExpression()->getQuery());
		self::assertCount(1, $selections[1]->getQuery()->getSelections());
		self::assertFalse(is_a($posts, ValueExpressionInterface::class));
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
		self::assertSame([], $posts->getSelections());
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

	private function makeRegistry(): Registry
	{
		$registry = new Registry();
		$users = $registry->collection('users');
		$users->field('id', 'int');
		$users->field('title', 'string');
		$users->field('email', 'string')->alias('mail_address');
		$users->field('active', 'bool');
		$users->field('status', 'string');
		$users->field('lastPostId', 'int');
		$users->field('deletedAt', 'datetime');
		$users->field('createdAt', 'datetime');
		$users->field('updatedAt', 'datetime');
		$users->relation('posts', CustomRelation::class);

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
