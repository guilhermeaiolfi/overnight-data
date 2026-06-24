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
use ON\Data\Query\Condition\LogicalCondition;
use ON\Data\Query\Condition\LogicalOperator;
use ON\Data\Query\Condition\NotCondition;
use ON\Data\Query\Condition\NullCondition;
use ON\Data\Query\Condition\NullOperator;
use ON\Data\Query\Exception\UnknownQueryFieldException;
use ON\Data\Query\Expression\AliasedExpression;
use ON\Data\Query\Expression\FieldRef;
use ON\Data\Query\Expression\LiteralExpression;
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

	private static function assertNullCondition(object $condition, NullOperator $operator, FieldRef $expression): void
	{
		self::assertInstanceOf(NullCondition::class, $condition);
		self::assertSame($operator, $condition->getOperator());
		self::assertSame($expression, $condition->getExpression());
	}

	private function makeRegistry(): Registry
	{
		$registry = new Registry();
		$users = $registry->collection('users');
		$users->field('id', 'int');
		$users->field('title', 'string');
		$users->field('email', 'string')->alias('mail_address');
		$users->field('active', 'bool');
		$users->field('deletedAt', 'datetime');
		$users->field('createdAt', 'datetime');
		$users->field('updatedAt', 'datetime');
		$users->relation('posts', CustomRelation::class);

		$view = $registry->view('user_summary');
		$view->source($users);
		$view->field('id', 'int');
		$view->field('title', 'string');

		return $registry;
	}
}
