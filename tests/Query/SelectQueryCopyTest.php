<?php

declare(strict_types=1);

namespace Tests\ON\Data\Query;

use ON\Data\Database\QueryExecutorInterface;
use ON\Data\Definition\Registry;
use ON\Data\Query\Condition\ExistsCondition;
use ON\Data\Query\Exception\DuplicateDerivedOutputColumnException;
use ON\Data\Query\Exception\UnknownQueryFieldException;
use ON\Data\Query\Expression\SourceFieldExpression;
use ON\Data\Query\Expression\SubqueryExpression;
use function ON\Data\Query\query;
use ON\Data\Query\SelectQuery;
use function ON\Data\Query\x;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use stdClass;

final class SelectQueryCopyTest extends TestCase
{
	public function testCopyYieldsNewRootAndLeavesOriginalUnchanged(): void
	{
		$users = $this->makeQuery('users');
		$users->where(x()->eq($users->name, 'Ada'))->limit(1)->to(stdClass::class);

		$copy = $users->copy();

		self::assertNotSame($users, $copy);
		self::assertSame($users->getConditions(), $users->getConditions());
		self::assertCount(1, $users->getConditions());
		self::assertSame(1, $users->getLimit());
		self::assertSame(stdClass::class, $users->getResultClass());
		self::assertCount(1, $copy->getConditions());
		self::assertSame(1, $copy->getLimit());
		self::assertSame(stdClass::class, $copy->getResultClass());
		self::assertNotSame($users->getConditions()[0], $copy->getConditions()[0]);
	}

	public function testCopyPreservesExecutorBinding(): void
	{
		$executor = new class () implements QueryExecutorInterface {
			public function fetchAll(SelectQuery $query): array
			{
				return [];
			}

			public function fetchOne(SelectQuery $query): ?array
			{
				return null;
			}

			public function iterate(SelectQuery $query): iterable
			{
				return [];
			}
		};

		$users = new SelectQuery($this->makeRegistry()->getCollection('users'), $executor);
		$copy = $users->copy();

		self::assertTrue($copy->isExecutable());
		$executorProperty = new ReflectionProperty(SelectQuery::class, 'executor');
		self::assertSame($executor, $executorProperty->getValue($copy));
	}

	public function testCopySharesDerivedFromInstance(): void
	{
		$inner = $this->makeQuery('users')->select(x()->literal(1)->as('marker'));
		$derived = $inner->as('derived_users');
		$outer = query($derived);
		$copy = $outer->copy();

		self::assertSame($derived, $outer->getFrom());
		self::assertSame($outer->getFrom(), $copy->getFrom());
	}

	public function testCopyRemountsExistsSubquery(): void
	{
		$users = $this->makeQuery('users');
		$users->where(x()->exists($users->relatedQuery($users->posts)));

		$copy = $users->copy();
		$condition = $copy->getConditions()[0];
		self::assertInstanceOf(ExistsCondition::class, $condition);
		self::assertNotSame(
			$users->getConditions()[0]->getQuery(),
			$condition->getQuery(),
		);
		self::assertSame($copy, $condition->getQuery()->getConditions()[0]->getRight()->getSource());
	}

	public function testCopyRemountsScalarSubqueryExpression(): void
	{
		$users = $this->makeQuery('users');
		$scalar = $users->relatedQuery($users->posts)->select($users->posts->id)->limit(1);
		$users->select((new SubqueryExpression($scalar))->as('post_id'));

		$copy = $users->copy();
		$expression = $copy->getSelections()->getNamedExpression('post_id');
		self::assertInstanceOf(SubqueryExpression::class, $expression);
		self::assertNotSame($scalar, $expression->getQuery());
	}

	public function testAliasedQueryRejectsUnprojectedCollectionFields(): void
	{
		$users = $this->makeQuery('users')->select(x()->literal(1)->as('marker'));
		$derived = $users->as('count_rows');

		$this->expectException(UnknownQueryFieldException::class);
		$derived->field('id');
	}

	public function testDerivedDefaultStarExposesVisibleFieldNames(): void
	{
		$posts = $this->makeQuery('posts');
		$derived = $posts->as('posts_view');

		self::assertInstanceOf(SourceFieldExpression::class, $derived->field('id'));
		self::assertSame('id', $derived->field('id')->getName());
		self::assertContains('id', $derived->selectionNames());
		self::assertContains('title', $derived->selectionNames());
		self::assertNotContains('*', $derived->selectionNames());
	}

	public function testNestedDerivedExposesCanonicalFieldNames(): void
	{
		$posts = $this->makeQuery('posts');
		$first = $posts->as('first');
		$middle = query($first)->select($first->field('id'));
		$second = $middle->as('second');

		self::assertInstanceOf(SourceFieldExpression::class, $second->field('id'));
		self::assertSame(['id'], $second->selectionNames());
	}

	public function testDerivedRelatedFieldRefExposesFieldNameNotPathKey(): void
	{
		$users = $this->makeQuery('users');
		$derived = $users->select($users->company->id)->as('users_view');

		self::assertSame('company.id', $users->company->id->getSelectionKey());
		self::assertSame(['id'], $derived->selectionNames());
		self::assertInstanceOf(SourceFieldExpression::class, $derived->field('id'));
	}

	public function testDerivedRejectsCollidingCanonicalOutputNames(): void
	{
		$users = $this->makeQuery('users');

		$this->expectException(DuplicateDerivedOutputColumnException::class);
		$this->expectExceptionMessage("duplicate output column 'id'");

		$users
			->select($users->id, $users->company->id)
			->as('user_company');
	}

	public function testDerivedAcceptsAliasedCollidingFieldNames(): void
	{
		$users = $this->makeQuery('users');
		$derived = $users
			->select($users->id, $users->company->id->as('company_id'))
			->as('user_company');

		self::assertSame(['id', 'company_id'], $derived->selectionNames());
		self::assertInstanceOf(SourceFieldExpression::class, $derived->field('company_id'));
	}

	public function testAliasedQueryExposesProjectedSelectionKeys(): void
	{
		$users = $this->makeQuery('users')->select(x()->literal(1)->as('marker'));
		$derived = $users->as('count_rows');

		self::assertInstanceOf(SourceFieldExpression::class, $derived->field('marker'));
	}

	public function testCopyPreservesSelectedRelations(): void
	{
		$users = $this->makeQuery('users');
		$users->posts->load();

		$copy = $users->copy();

		self::assertTrue($users->posts->isSelected());
		self::assertTrue($copy->posts->isSelected());
		self::assertFalse($copy->getRelationSelections()->isEmpty());
	}

	private function makeQuery(string $collection): SelectQuery
	{
		return query($this->makeRegistry()->getCollection($collection));
	}

	private function makeRegistry(): Registry
	{
		$registry = new Registry();

		$companies = $registry->collection('companies');
		$companies->field('id', 'int');
		$companies->field('name', 'string');
		$companies->primaryKey('id');

		$users = $registry->collection('users');
		$users->field('id', 'int');
		$users->field('name', 'string');
		$users->field('companyId', 'int')->nullable(true);
		$users->belongsTo('company', 'companies')->innerKey('companyId')->outerKey('id')->end();
		$users->hasMany('posts', 'posts')->innerKey('id')->outerKey('userId')->end();
		$users->primaryKey('id');

		$posts = $registry->collection('posts');
		$posts->field('id', 'int');
		$posts->field('userId', 'int');
		$posts->field('title', 'string');
		$posts->primaryKey('id');

		return $registry;
	}
}
