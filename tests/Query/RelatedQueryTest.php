<?php

declare(strict_types=1);

namespace Tests\ON\Data\Query;

use ON\Data\Definition\Registry;
use ON\Data\Definition\Relation\FirstOfManyRelation;
use ON\Data\Definition\Relation\M2MRelation;
use ON\Data\Query\Condition\ComparisonCondition;
use ON\Data\Query\Condition\ComparisonOperator;
use ON\Data\Query\Condition\ExistsCondition;
use ON\Data\Query\Exception\RelationQueryException;
use ON\Data\Query\Join;
use ON\Data\Query\JoinType;
use function ON\Data\Query\query;
use ON\Data\Query\SelectQuery;
use function ON\Data\Query\x;
use PHPUnit\Framework\TestCase;

final class RelatedQueryTest extends TestCase
{
	public function testHasManyRelatedQueryCorrelatesOuterKeyToParent(): void
	{
		$users = $this->makeQuery('users');
		$posts = $users->relatedQuery($users->posts);

		self::assertSame('posts', $posts->getCollection()->getName());
		self::assertFalse($users->posts->isSelected());
		self::assertSame([], $users->getJoins());

		$conditions = $posts->getConditions();
		self::assertCount(1, $conditions);
		self::assertInstanceOf(ComparisonCondition::class, $conditions[0]);
		self::assertSame(ComparisonOperator::EQ, $conditions[0]->getOperator());
		self::assertSame('userId', $conditions[0]->getLeft()->getName());
		self::assertSame($posts, $conditions[0]->getLeft()->getSource());
		self::assertSame('id', $conditions[0]->getRight()->getName());
		self::assertSame($users, $conditions[0]->getRight()->getSource());
	}

	public function testBelongsToAndHasOneRelatedQueryCorrelate(): void
	{
		$users = $this->makeQuery('users');

		$company = $users->relatedQuery($users->company);
		self::assertSame('id', $company->getConditions()[0]->getLeft()->getName());
		self::assertSame('companyId', $company->getConditions()[0]->getRight()->getName());

		$profile = $users->relatedQuery($users->profile);
		self::assertSame('userId', $profile->getConditions()[0]->getLeft()->getName());
		self::assertSame('id', $profile->getConditions()[0]->getRight()->getName());
	}

	public function testCompositeDirectRelatedQueryAddsEveryPair(): void
	{
		$accounts = $this->makeQuery('accounts');
		$employees = $accounts->relatedQuery($accounts->employees);

		$conditions = $employees->getConditions();
		self::assertCount(2, $conditions);
		self::assertSame('tenantId', $conditions[0]->getLeft()->getName());
		self::assertSame('tenantId', $conditions[0]->getRight()->getName());
		self::assertSame('accountId', $conditions[1]->getLeft()->getName());
		self::assertSame('id', $conditions[1]->getRight()->getName());
	}

	public function testM2MRelatedQueryJoinsThroughAndCorrelatesParent(): void
	{
		$users = $this->makeQuery('users');
		$roles = $users->relatedQuery($users->roles);

		$joins = $roles->getJoins();
		self::assertCount(1, $joins);
		self::assertInstanceOf(Join::class, $joins[0]);
		self::assertSame(JoinType::INNER, $joins[0]->getType());
		self::assertSame('user_roles', $joins[0]->getCollection()->getName());

		$joinConditions = $joins[0]->getConditions();
		self::assertCount(1, $joinConditions);
		self::assertSame('id', $joinConditions[0]->getLeft()->getName());
		self::assertSame($roles, $joinConditions[0]->getLeft()->getSource());
		self::assertSame('role_id', $joinConditions[0]->getRight()->getName());

		$where = $roles->getConditions();
		self::assertCount(1, $where);
		self::assertSame('user_id', $where[0]->getLeft()->getName());
		self::assertSame($joins[0], $where[0]->getLeft()->getSource());
		self::assertSame('id', $where[0]->getRight()->getName());
		self::assertSame($users, $where[0]->getRight()->getSource());
	}

	public function testCompositeM2MRelatedQueryUsesBothHops(): void
	{
		$articles = $this->makeQuery('composite_articles');
		$tags = $articles->relatedQuery($articles->tags);

		self::assertCount(1, $tags->getJoins());
		self::assertCount(2, $tags->getJoins()[0]->getConditions());
		self::assertCount(2, $tags->getConditions());
	}

	public function testRelatedQueryCallbackReceivesTargetAndIgnoresReturnValue(): void
	{
		$users = $this->makeQuery('users');
		$seen = null;

		$posts = $users->relatedQuery($users->posts, function (SelectQuery $target) use (&$seen): string {
			$seen = $target;
			$target->where(x()->eq($target->published, true));

			return 'ignored';
		});

		self::assertSame($posts, $seen);
		self::assertCount(2, $posts->getConditions());
		self::assertFalse($users->posts->isSelected());
		self::assertSame([], $users->getJoins());
	}

	public function testNestedRelatedQueryOwnsIntermediateSource(): void
	{
		$users = $this->makeQuery('users');
		$posts = $users->relatedQuery(
			$users->posts,
			function (SelectQuery $posts): void {
				$posts->where(
					x()->exists(
						$posts->relatedQuery(
							$posts->comments,
							fn (SelectQuery $comments) => $comments->where(x()->eq($comments->spam, false)),
						),
					),
				);
			},
		);

		$nestedExists = $posts->getConditions()[1];
		self::assertInstanceOf(ExistsCondition::class, $nestedExists);
		$comments = $nestedExists->getQuery();
		self::assertSame('comments', $comments->getCollection()->getName());
		self::assertSame($posts, $comments->getConditions()[0]->getRight()->getSource());
	}

	public function testRejectsForeignRelationRef(): void
	{
		$users = $this->makeQuery('users');
		$other = $this->makeQuery('users');

		$this->expectException(RelationQueryException::class);
		$this->expectExceptionMessage('belongs to a different query');
		$users->relatedQuery($other->posts);
	}

	public function testMultiHopRelatedQueryCorrelatesToParentRelation(): void
	{
		$users = $this->makeQuery('users');
		$comments = $users->relatedQuery($users->posts->comments);

		self::assertSame('comments', $comments->getCollection()->getName());
		self::assertSame($users->posts, $comments->getConditions()[0]->getRight()->getSource());
	}

	public function testRejectsFirstOfManyRelatedQuery(): void
	{
		$users = $this->makeQuery('users');

		$this->expectException(RelationQueryException::class);
		$this->expectExceptionMessage('first-of-many');
		$users->relatedQuery($users->latestPost);
	}

	public function testCopyDoesNotCorruptCorrelatedParentReferences(): void
	{
		$users = $this->makeQuery('users');
		$posts = $users->relatedQuery($users->posts);
		$copy = $users->copy();

		$condition = $posts->getConditions()[0];
		self::assertSame($users, $condition->getRight()->getSource());
		self::assertNotSame($copy, $condition->getRight()->getSource());

		$copiedRelated = $copy->relatedQuery($copy->posts);
		self::assertSame($copy, $copiedRelated->getConditions()[0]->getRight()->getSource());
	}

	private function makeQuery(string $collection): SelectQuery
	{
		return query($this->makeRegistry()->getCollection($collection));
	}

	private function makeRegistry(): Registry
	{
		$registry = new Registry();

		$profiles = $registry->collection('profiles');
		$profiles->field('id', 'int');
		$profiles->field('userId', 'int');
		$profiles->field('bio', 'string');
		$profiles->primaryKey('id');

		$companies = $registry->collection('companies');
		$companies->field('id', 'int');
		$companies->field('name', 'string');
		$companies->primaryKey('id');

		$users = $registry->collection('users');
		$users->field('id', 'int');
		$users->field('name', 'string');
		$users->field('companyId', 'int')->nullable(true);
		$users->hasOne('profile', 'profiles')->innerKey('id')->outerKey('userId')->end();
		$users->belongsTo('company', 'companies')->innerKey('companyId')->outerKey('id')->end();
		$users->hasMany('posts', 'posts')->innerKey('id')->outerKey('userId')->end();
		$users->relation('roles', M2MRelation::class)
			->collection('roles')
			->innerKey('id')
			->outerKey('id')
			->through('user_roles')
				->innerKey('user_id')
				->outerKey('role_id')
				->end();
		$users->relation('latestPost', FirstOfManyRelation::class)
			->collection('posts')
			->innerKey('id')
			->outerKey('userId')
			->orderBy(['id' => 'desc']);
		$users->primaryKey('id');

		$posts = $registry->collection('posts');
		$posts->field('id', 'int');
		$posts->field('userId', 'int');
		$posts->field('title', 'string');
		$posts->field('published', 'bool');
		$posts->hasMany('comments', 'comments')->innerKey('id')->outerKey('postId')->end();
		$posts->primaryKey('id');

		$comments = $registry->collection('comments');
		$comments->field('id', 'int');
		$comments->field('postId', 'int');
		$comments->field('spam', 'bool');
		$comments->primaryKey('id');

		$roles = $registry->collection('roles');
		$roles->field('id', 'int');
		$roles->field('name', 'string');
		$roles->primaryKey('id');

		$userRoles = $registry->collection('user_roles');
		$userRoles->field('user_id', 'int');
		$userRoles->field('role_id', 'int');

		$accounts = $registry->collection('accounts');
		$accounts->field('tenantId', 'int');
		$accounts->field('id', 'int');
		$accounts->hasMany('employees', 'employees')
			->innerKey(['tenantId', 'id'])
			->outerKey(['tenantId', 'accountId'])
			->end();
		$accounts->primaryKey('tenantId', 'id');

		$employees = $registry->collection('employees');
		$employees->field('tenantId', 'int');
		$employees->field('accountId', 'int');
		$employees->field('name', 'string');
		$employees->primaryKey('tenantId', 'name');

		$articles = $registry->collection('composite_articles');
		$articles->field('tenantId', 'int');
		$articles->field('slug', 'string');
		$articles->relation('tags', M2MRelation::class)
			->collection('composite_tags')
			->innerKey(['tenantId', 'slug'])
			->outerKey(['tenantId', 'slug'])
			->through('composite_article_tag')
				->innerKey(['article_tenant_id', 'article_slug'])
				->outerKey(['tag_tenant_id', 'tag_slug'])
				->end();
		$articles->primaryKey('tenantId', 'slug');

		$tags = $registry->collection('composite_tags');
		$tags->field('tenantId', 'int');
		$tags->field('slug', 'string');
		$tags->primaryKey('tenantId', 'slug');

		$pivot = $registry->collection('composite_article_tag');
		$pivot->field('article_tenant_id', 'int');
		$pivot->field('article_slug', 'string');
		$pivot->field('tag_tenant_id', 'int');
		$pivot->field('tag_slug', 'string');

		return $registry;
	}
}
