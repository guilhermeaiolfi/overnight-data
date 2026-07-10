<?php

declare(strict_types=1);

namespace Tests\ON\Data\Query;

use InvalidArgumentException;
use ON\Data\Definition\Registry;
use ON\Data\Query\Condition\ComparisonCondition;
use ON\Data\Query\Condition\ComparisonOperator;
use ON\Data\Query\Expression\AggregateExpression;
use ON\Data\Query\Expression\AggregateFunction;
use ON\Data\Query\Expression\LiteralExpression;
use function ON\Data\Query\query;
use function ON\Data\Query\x;
use PHPUnit\Framework\TestCase;
use TypeError;

final class LikeAndAggregateTest extends TestCase
{
	// -----------------------------------------------------------------------
	// LIKE / NOT LIKE — factory
	// -----------------------------------------------------------------------

	public function testLikeBuildsComparisonConditionWithLiteralPattern(): void
	{
		$users = query($this->makeRegistry()->getCollection('users'));
		$condition = x()->like($users->name, 'Ada%');

		self::assertInstanceOf(ComparisonCondition::class, $condition);
		self::assertSame($users->name, $condition->getLeft());
		self::assertSame(ComparisonOperator::LIKE, $condition->getOperator());
		self::assertInstanceOf(LiteralExpression::class, $condition->getRight());
		self::assertSame('Ada%', $condition->getRight()->getValue());
	}

	public function testNotLikeBuildsComparisonConditionWithLiteralPattern(): void
	{
		$users = query($this->makeRegistry()->getCollection('users'));
		$condition = x()->notLike($users->name, '%admin%');

		self::assertInstanceOf(ComparisonCondition::class, $condition);
		self::assertSame($users->name, $condition->getLeft());
		self::assertSame(ComparisonOperator::NOT_LIKE, $condition->getOperator());
		self::assertInstanceOf(LiteralExpression::class, $condition->getRight());
		self::assertSame('%admin%', $condition->getRight()->getValue());
	}

	public function testLikeAcceptsExpressionAsPattern(): void
	{
		$users = query($this->makeRegistry()->getCollection('users'));
		$condition = x()->like($users->name, $users->nickname);

		self::assertSame(ComparisonOperator::LIKE, $condition->getOperator());
		self::assertSame($users->nickname, $condition->getRight());
	}

	public function testLikeRejectsNullPattern(): void
	{
		$users = query($this->makeRegistry()->getCollection('users'));

		$this->expectException(InvalidArgumentException::class);
		x()->like($users->name, null);
	}

	public function testNotLikeRejectsNullPattern(): void
	{
		$users = query($this->makeRegistry()->getCollection('users'));

		$this->expectException(InvalidArgumentException::class);
		x()->notLike($users->name, null);
	}

	// -----------------------------------------------------------------------
	// Convenience wrappers — contains / notContains / startsWith / endsWith
	// -----------------------------------------------------------------------

	public function testContainsWrapsWith(): void
	{
		$users = query($this->makeRegistry()->getCollection('users'));
		$condition = x()->contains($users->name, 'ad');

		self::assertSame(ComparisonOperator::LIKE, $condition->getOperator());
		self::assertInstanceOf(LiteralExpression::class, $condition->getRight());
		self::assertSame('%ad%', $condition->getRight()->getValue());
	}

	public function testNotContainsWrapsWithPercentAndNotLike(): void
	{
		$users = query($this->makeRegistry()->getCollection('users'));
		$condition = x()->notContains($users->name, 'bot');

		self::assertSame(ComparisonOperator::NOT_LIKE, $condition->getOperator());
		self::assertInstanceOf(LiteralExpression::class, $condition->getRight());
		self::assertSame('%bot%', $condition->getRight()->getValue());
	}

	public function testStartsWithAppendsTrailingPercent(): void
	{
		$users = query($this->makeRegistry()->getCollection('users'));
		$condition = x()->startsWith($users->name, 'Ada');

		self::assertSame(ComparisonOperator::LIKE, $condition->getOperator());
		self::assertSame('Ada%', $condition->getRight()->getValue());
	}

	public function testEndsWithPrependLeadingPercent(): void
	{
		$users = query($this->makeRegistry()->getCollection('users'));
		$condition = x()->endsWith($users->name, 'ace');

		self::assertSame(ComparisonOperator::LIKE, $condition->getOperator());
		self::assertSame('%ace', $condition->getRight()->getValue());
	}

	// -----------------------------------------------------------------------
	// Fluent shorthands on field ref
	// -----------------------------------------------------------------------

	public function testFluentLikeAndNotLikeMatchFactoryOutput(): void
	{
		$users = query($this->makeRegistry()->getCollection('users'));

		$fluent = $users->name->like('G%');
		$factory = x()->like($users->name, 'G%');

		self::assertSame($fluent->getOperator(), $factory->getOperator());
		self::assertSame($fluent->getLeft(), $factory->getLeft());
		self::assertSame($fluent->getRight()->getValue(), $factory->getRight()->getValue());
	}

	public function testFluentNotLikeMatchesFactory(): void
	{
		$users = query($this->makeRegistry()->getCollection('users'));

		$fluent = $users->name->notLike('G%');
		$factory = x()->notLike($users->name, 'G%');

		self::assertSame(ComparisonOperator::NOT_LIKE, $fluent->getOperator());
		self::assertSame($fluent->getOperator(), $factory->getOperator());
	}

	public function testFluentContainsMatchesFactory(): void
	{
		$users = query($this->makeRegistry()->getCollection('users'));

		$fluent = $users->name->contains('ac');
		$factory = x()->contains($users->name, 'ac');

		self::assertSame(ComparisonOperator::LIKE, $fluent->getOperator());
		self::assertSame('%ac%', $fluent->getRight()->getValue());
		self::assertSame($fluent->getRight()->getValue(), $factory->getRight()->getValue());
	}

	public function testFluentStartsWithAndEndsWithMatchFactory(): void
	{
		$users = query($this->makeRegistry()->getCollection('users'));

		$sw = $users->name->startsWith('Li');
		$ew = $users->name->endsWith('us');

		self::assertSame('Li%', $sw->getRight()->getValue());
		self::assertSame('%us', $ew->getRight()->getValue());
	}

	public function testFluentNotContainsMatchesFactory(): void
	{
		$users = query($this->makeRegistry()->getCollection('users'));

		$fluent = $users->name->notContains('bot');
		self::assertSame(ComparisonOperator::NOT_LIKE, $fluent->getOperator());
		self::assertSame('%bot%', $fluent->getRight()->getValue());
	}

	// -----------------------------------------------------------------------
	// AVG / MIN / MAX — factory
	// -----------------------------------------------------------------------

	public function testAvgBuildsAggregateExpressionWithCorrectFunction(): void
	{
		$posts = query($this->makeRegistry()->getCollection('posts'));
		$agg = x()->avg($posts->amount);

		self::assertInstanceOf(AggregateExpression::class, $agg);
		self::assertSame(AggregateFunction::AVG, $agg->getFunction());
		self::assertSame($posts->amount, $agg->getExpression());
	}

	public function testMinBuildsAggregateExpressionWithCorrectFunction(): void
	{
		$posts = query($this->makeRegistry()->getCollection('posts'));
		$agg = x()->min($posts->amount);

		self::assertInstanceOf(AggregateExpression::class, $agg);
		self::assertSame(AggregateFunction::MIN, $agg->getFunction());
		self::assertSame($posts->amount, $agg->getExpression());
	}

	public function testMaxBuildsAggregateExpressionWithCorrectFunction(): void
	{
		$posts = query($this->makeRegistry()->getCollection('posts'));
		$agg = x()->max($posts->amount);

		self::assertInstanceOf(AggregateExpression::class, $agg);
		self::assertSame(AggregateFunction::MAX, $agg->getFunction());
		self::assertSame($posts->amount, $agg->getExpression());
	}

	public function testAvgMinMaxRejectAliasedExpressions(): void
	{
		$posts = query($this->makeRegistry()->getCollection('posts'));
		$alias = $posts->amount->as('total');

		// AliasedExpression does not implement ValueExpressionInterface, so PHP
		// enforces the rejection at the call site with a TypeError.
		foreach (['avg', 'min', 'max'] as $method) {
			try {
				x()->{$method}($alias);
				self::fail(sprintf('Expected %s() to reject AliasedExpression.', $method));
			} catch (TypeError) {
				self::assertTrue(true);
			}
		}
	}

	public function testAvgMinMaxRejectNestedAggregates(): void
	{
		$posts = query($this->makeRegistry()->getCollection('posts'));
		$sum = x()->sum($posts->amount);

		foreach (['avg', 'min', 'max'] as $method) {
			try {
				x()->{$method}($sum);
				self::fail(sprintf('Expected %s() to reject nested aggregate.', $method));
			} catch (InvalidArgumentException $exception) {
				self::assertStringContainsString('Aggregate expressions cannot be aggregated directly', $exception->getMessage());
			}
		}
	}

	// -----------------------------------------------------------------------
	// Fluent shorthands on field ref for AVG / MIN / MAX
	// -----------------------------------------------------------------------

	public function testFluentAvgMinMaxMatchFactoryOutput(): void
	{
		$posts = query($this->makeRegistry()->getCollection('posts'));

		$fluentAvg = $posts->amount->avg();
		$factoryAvg = x()->avg($posts->amount);

		$fluentMin = $posts->amount->min();
		$factoryMin = x()->min($posts->amount);

		$fluentMax = $posts->amount->max();
		$factoryMax = x()->max($posts->amount);

		self::assertSame($fluentAvg->getFunction(), $factoryAvg->getFunction());
		self::assertSame($fluentAvg->getExpression(), $factoryAvg->getExpression());

		self::assertSame($fluentMin->getFunction(), $factoryMin->getFunction());
		self::assertSame($fluentMin->getExpression(), $factoryMin->getExpression());

		self::assertSame($fluentMax->getFunction(), $factoryMax->getFunction());
		self::assertSame($fluentMax->getExpression(), $factoryMax->getExpression());
	}

	public function testAvgMinMaxCanBeAliasedForSelection(): void
	{
		$posts = query($this->makeRegistry()->getCollection('posts'));

		$avg = $posts->amount->avg()->as('avg_amount');
		$min = $posts->amount->min()->as('min_amount');
		$max = $posts->amount->max()->as('max_amount');

		self::assertSame('avg_amount', $avg->getAlias());
		self::assertSame('min_amount', $min->getAlias());
		self::assertSame('max_amount', $max->getAlias());
	}

	// -----------------------------------------------------------------------
	// Fixture
	// -----------------------------------------------------------------------

	private function makeRegistry(): Registry
	{
		$registry = new Registry();

		$users = $registry->collection('users');
		$users->field('id', 'int');
		$users->field('name', 'string');
		$users->field('nickname', 'string');

		$posts = $registry->collection('posts');
		$posts->field('id', 'int');
		$posts->field('amount', 'float');

		return $registry;
	}
}
