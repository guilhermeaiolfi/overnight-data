<?php

declare(strict_types=1);

namespace Tests\ON\Data\Query;

use ON\Data\Definition\Registry;
use ON\Data\Query\Exception\UnknownQueryFieldException;
use ON\Data\Query\Exception\UnknownQueryMemberException;
use ON\Data\Query\Exception\UnknownQueryRelationException;
use ON\Data\Query\Expression\FieldRef;
use ON\Data\Query\Expression\ValueExpressionInterface;
use function ON\Data\Query\query;
use ON\Data\Query\Relation\RelationRef;
use PHPUnit\Framework\TestCase;
use Tests\ON\Data\Fixture\CustomRelation;
use TypeError;

final class RelationRefTest extends TestCase
{
	public function testRootFieldPropertyAccessRemainsCachedAndUnchanged(): void
	{
		$users = query($this->makeRegistry()->getCollection('users'));

		self::assertInstanceOf(FieldRef::class, $users->name);
		self::assertNull($users->name->getRelation());
		self::assertSame(['name'], $users->name->getPath());
		self::assertSame($users->name, $users->field('name'));
	}

	public function testRootRelationPropertyAccessExposesRelationMetadata(): void
	{
		$users = query($this->makeRegistry()->getCollection('users'));
		$posts = $users->posts;

		self::assertInstanceOf(RelationRef::class, $posts);
		self::assertSame($users, $posts->getQuery());
		self::assertSame($users->getSource()->getRelation('posts'), $posts->getRelation());
		self::assertNull($posts->getParentRelation());
		self::assertSame('posts', $posts->getCollection()->getName());
		self::assertSame('posts', $posts->getName());
		self::assertSame(['posts'], $posts->getPath());
	}

	public function testExplicitAndPropertyRelationCachingMatch(): void
	{
		$users = query($this->makeRegistry()->getCollection('users'));

		self::assertSame($users->posts, $users->relation('posts'));
		self::assertSame($users->posts, $users->posts);
	}

	public function testRelatedFieldAccessIsCachedAndRetainsRootQuery(): void
	{
		$users = query($this->makeRegistry()->getCollection('users'));
		$title = $users->posts->title;

		self::assertInstanceOf(FieldRef::class, $title);
		self::assertSame($users, $title->getQuery());
		self::assertSame($users->posts->getCollection()->getField('title'), $title->getField());
		self::assertSame($users->posts, $title->getRelation());
		self::assertSame(['posts', 'title'], $title->getPath());
		self::assertSame($title, $users->posts->title);
	}

	public function testNestedRelationTraversalRetainsRootQueryAndPath(): void
	{
		$users = query($this->makeRegistry()->getCollection('users'));
		$author = $users->posts->author;
		$name = $author->name;

		self::assertSame(['posts', 'author'], $author->getPath());
		self::assertSame(['posts', 'author', 'name'], $name->getPath());
		self::assertSame($users, $author->getQuery());
		self::assertSame($users, $name->getQuery());
	}

	public function testNestedCacheIdentityMatchesExplicitLookup(): void
	{
		$users = query($this->makeRegistry()->getCollection('users'));

		self::assertSame($users->posts->author, $users->posts->relation('author'));
		self::assertSame($users->posts->author->name, $users->posts->author->field('name'));
	}

	public function testSeparatePathsToSameTargetRemainDistinct(): void
	{
		$orders = query($this->makeRegistry()->getCollection('orders'));

		self::assertNotSame($orders->billingAddress, $orders->shippingAddress);
		self::assertNotSame($orders->billingAddress->city, $orders->shippingAddress->city);
		self::assertSame(['billingAddress', 'city'], $orders->billingAddress->city->getPath());
		self::assertSame(['shippingAddress', 'city'], $orders->shippingAddress->city->getPath());
	}

	public function testSelfRelationTraversalIsLazyAndSafe(): void
	{
		$employees = query($this->makeRegistry()->getCollection('employees'));
		$name = $employees->manager->manager->name;

		self::assertSame(['manager', 'manager', 'name'], $name->getPath());
	}

	public function testUnknownExplicitRootRelationThrowsSpecificException(): void
	{
		$users = query($this->makeRegistry()->getCollection('users'));

		$this->expectException(UnknownQueryRelationException::class);
		$this->expectExceptionMessage("Unknown query relation 'missing' on definition 'users'.");
		$users->relation('missing');
	}

	public function testUnknownExplicitNestedRelationThrowsSpecificException(): void
	{
		$users = query($this->makeRegistry()->getCollection('users'));

		$this->expectException(UnknownQueryRelationException::class);
		$this->expectExceptionMessage("Unknown query relation 'missing' on definition 'posts'.");
		$users->posts->relation('missing');
	}

	public function testUnknownRootPropertyMemberThrowsSpecificException(): void
	{
		$users = query($this->makeRegistry()->getCollection('users'));

		$this->expectException(UnknownQueryMemberException::class);
		$this->expectExceptionMessage("Unknown query member 'missing' on definition 'users'.");
		$users->missing;
	}

	public function testUnknownNestedPropertyMemberThrowsSpecificException(): void
	{
		$users = query($this->makeRegistry()->getCollection('users'));

		$this->expectException(UnknownQueryMemberException::class);
		$this->expectExceptionMessage("Unknown query member 'missing' on definition 'posts'.");
		$users->posts->missing;
	}

	public function testExistingExplicitFieldErrorRemainsUnchanged(): void
	{
		$users = query($this->makeRegistry()->getCollection('users'));

		$this->expectException(UnknownQueryFieldException::class);
		$this->expectExceptionMessage("Unknown query field 'missing' on definition 'users'.");
		$users->field('missing');
	}

	public function testAccessHasNoQuerySideEffects(): void
	{
		$users = query($this->makeRegistry()->getCollection('users'));
		$users->posts;
		$users->posts->title;
		$users->posts->author->name;

		self::assertCount(0, $users->getSelections());
		self::assertSame([], $users->getConditions());
		self::assertSame([], $users->getGroups());
		self::assertSame([], $users->getSorts());
	}

	public function testRelationRefIsNotSelectableOrAValueExpression(): void
	{
		$users = query($this->makeRegistry()->getCollection('users'));

		self::assertFalse(is_a($users->posts, ValueExpressionInterface::class));
		$this->expectException(TypeError::class);
		$users->select($users->posts);
	}

	private function makeRegistry(): Registry
	{
		$registry = new Registry();

		$addresses = $registry->collection('addresses');
		$addresses->field('id', 'int');
		$addresses->field('city', 'string');

		$users = $registry->collection('users');
		$users->field('id', 'int');
		$users->field('name', 'string');
		$users->field('managerId', 'int');
		$users->relation('posts', CustomRelation::class)->collection('posts');
		$users->relation('manager', CustomRelation::class)->collection('employees');

		$posts = $registry->collection('posts');
		$posts->field('id', 'int');
		$posts->field('title', 'string');
		$posts->field('published', 'bool');
		$posts->relation('author', CustomRelation::class)->collection('users');

		$orders = $registry->collection('orders');
		$orders->field('id', 'int');
		$orders->relation('billingAddress', CustomRelation::class)->collection('addresses');
		$orders->relation('shippingAddress', CustomRelation::class)->collection('addresses');

		$employees = $registry->collection('employees');
		$employees->field('id', 'int');
		$employees->field('name', 'string');
		$employees->relation('manager', CustomRelation::class)->collection('employees');

		return $registry;
	}
}
