<?php

declare(strict_types=1);

namespace Tests\ON\Data\Query;

use ON\Data\Definition\Registry;
use ON\Data\Query\Exception\RelationSelectionException;
use ON\Data\Query\Exception\UnknownQueryFieldException;
use ON\Data\Query\Exception\UnknownQueryMemberException;
use ON\Data\Query\Exception\UnknownQueryRelationException;
use ON\Data\Query\Expression\FieldRef;
use ON\Data\Query\Expression\ValueExpressionInterface;
use function ON\Data\Query\query;
use ON\Data\Query\Relation\RelationRef;
use ON\Data\Query\SelectQuery;
use PHPUnit\Framework\TestCase;
use Tests\ON\Data\Fixture\CustomRelation;

final class RelationRefTest extends TestCase
{
	public function testRootFieldPropertyAccessRemainsCachedAndUnchanged(): void
	{
		$users = $this->makeQuery('users');

		self::assertInstanceOf(FieldRef::class, $users->name);
		self::assertSame($users, $users->name->getSource());
		self::assertSame(['name'], $users->name->getPath());
		self::assertSame($users->name, $users->field('name'));
	}

	public function testRootRelationPropertyAccessExposesRelationMetadata(): void
	{
		$users = $this->makeQuery('users');
		$posts = $users->posts;

		self::assertInstanceOf(RelationRef::class, $posts);
		self::assertSame($users, $posts->getQuery());
		self::assertSame($users->getCollection()->getRelation('posts'), $posts->getRelation());
		self::assertNull($posts->getParentRelation());
		self::assertSame('posts', $posts->getCollection()->getName());
		self::assertSame('posts', $posts->getName());
		self::assertSame(['posts'], $posts->getPath());
	}

	public function testExplicitAndPropertyRelationCachingMatch(): void
	{
		$users = $this->makeQuery('users');

		self::assertSame($users->posts, $users->relation('posts'));
		self::assertSame($users->posts, $users->posts);
	}

	public function testRelatedFieldAccessIsCachedAndRetainsRootQuery(): void
	{
		$users = $this->makeQuery('users');
		$title = $users->posts->title;

		self::assertInstanceOf(FieldRef::class, $title);
		self::assertSame($users, $title->getQuery());
		self::assertSame($users->posts->getCollection()->getField('title'), $title->getField());
		self::assertSame($users->posts, $title->getSource());
		self::assertSame(['posts', 'title'], $title->getPath());
		self::assertSame($title, $users->posts->title);
	}

	public function testNestedRelationTraversalRetainsRootQueryAndPath(): void
	{
		$users = $this->makeQuery('users');
		$author = $users->posts->author;
		$name = $author->name;

		self::assertSame(['posts', 'author'], $author->getPath());
		self::assertSame(['posts', 'author', 'name'], $name->getPath());
		self::assertSame($users, $author->getQuery());
		self::assertSame($users, $name->getQuery());
	}

	public function testNestedCacheIdentityMatchesExplicitLookup(): void
	{
		$users = $this->makeQuery('users');

		self::assertSame($users->posts->author, $users->posts->relation('author'));
		self::assertSame($users->posts->author->name, $users->posts->author->field('name'));
	}

	public function testRelationConfigurationMethodsSupportImmutableSelectionOptions(): void
	{
		$users = $this->makeQuery('users');
		$default = $users->posts;
		$loaded = $users->posts->load();
		$hidden = $users->posts->hidden();
		$explicitHidden = $users->posts->visible(false);
		$fields = $users->posts->fields('id', 'title', 'id');

		self::assertFalse($default->isLoaded());
		self::assertTrue($default->isVisible());
		self::assertNull($default->getFields());
		self::assertTrue($loaded->isLoaded());
		self::assertTrue($loaded->isVisible());
		self::assertFalse($hidden->isLoaded());
		self::assertFalse($hidden->isVisible());
		self::assertFalse($explicitHidden->isVisible());
		self::assertSame(['id', 'title'], $fields->getFields());
		self::assertEquals($hidden, $explicitHidden);
		self::assertSame($default, $users->posts);
		self::assertNotSame($default, $loaded);
		self::assertNotSame($default, $hidden);
		self::assertNotSame($default, $fields);
	}

	public function testFieldsAcceptArrayAndFieldRefs(): void
	{
		$users = $this->makeQuery('users');
		$fromArray = $users->posts->fields(['title']);
		$fromRefs = $users->posts->fields($users->posts->id, $users->posts->title);

		self::assertSame(['title'], $fromArray->getFields());
		self::assertSame(['id', 'title'], $fromRefs->getFields());
		self::assertNull($users->posts->getFields());
	}

	public function testInvalidRelationFieldSelectionsAreRejected(): void
	{
		$users = $this->makeQuery('users');

		foreach ([
			static fn () => $users->posts->fields(),
			static fn () => $users->posts->fields(''),
			static fn () => $users->posts->fields(['id' => 'title']),
			static fn () => $users->posts->fields([new \stdClass()]),
			static fn () => $users->posts->fields($users->name),
			static fn () => $users->posts->fields('missing'),
		] as $call) {
			try {
				$call();
				self::fail('Expected invalid relation-selection options to throw.');
			} catch (RelationSelectionException) {
				self::assertTrue(true);
			}
		}
	}

	public function testSeparatePathsToSameTargetRemainDistinct(): void
	{
		$orders = $this->makeQuery('orders');

		self::assertNotSame($orders->billingAddress, $orders->shippingAddress);
		self::assertNotSame($orders->billingAddress->city, $orders->shippingAddress->city);
		self::assertSame(['billingAddress', 'city'], $orders->billingAddress->city->getPath());
		self::assertSame(['shippingAddress', 'city'], $orders->shippingAddress->city->getPath());
	}

	public function testSelfRelationTraversalIsLazyAndSafe(): void
	{
		$employees = $this->makeQuery('employees');
		$name = $employees->manager->manager->name;

		self::assertSame(['manager', 'manager', 'name'], $name->getPath());
	}

	public function testUnknownExplicitRootRelationThrowsSpecificException(): void
	{
		$users = $this->makeQuery('users');

		$this->expectException(UnknownQueryRelationException::class);
		$this->expectExceptionMessage("Unknown query relation 'missing' on definition 'users'.");
		$users->relation('missing');
	}

	public function testUnknownExplicitNestedRelationThrowsSpecificException(): void
	{
		$users = $this->makeQuery('users');

		$this->expectException(UnknownQueryRelationException::class);
		$this->expectExceptionMessage("Unknown query relation 'missing' on definition 'posts'.");
		$users->posts->relation('missing');
	}

	public function testUnknownRootPropertyMemberThrowsSpecificException(): void
	{
		$users = $this->makeQuery('users');

		$this->expectException(UnknownQueryMemberException::class);
		$this->expectExceptionMessage("Unknown query member 'missing' on definition 'users'.");
		$users->missing;
	}

	public function testUnknownNestedPropertyMemberThrowsSpecificException(): void
	{
		$users = $this->makeQuery('users');

		$this->expectException(UnknownQueryMemberException::class);
		$this->expectExceptionMessage("Unknown query member 'missing' on definition 'posts'.");
		$users->posts->missing;
	}

	public function testExistingExplicitFieldErrorRemainsUnchanged(): void
	{
		$users = $this->makeQuery('users');

		$this->expectException(UnknownQueryFieldException::class);
		$this->expectExceptionMessage("Unknown query field 'missing' on definition 'users'.");
		$users->field('missing');
	}

	public function testAccessHasNoQuerySideEffects(): void
	{
		$users = $this->makeQuery('users');
		$users->posts;
		$users->posts->title;
		$users->posts->author->name;

		self::assertCount(0, $users->getSelections());
		self::assertSame([], $users->getConditions());
		self::assertSame([], $users->getGroups());
		self::assertSame([], $users->getSorts());
	}

	public function testRelationRefIsSelectableWithoutBecomingAValueExpression(): void
	{
		$users = $this->makeQuery('users');

		self::assertFalse(is_a($users->posts, ValueExpressionInterface::class));
		$users->select($users->posts);

		self::assertCount(0, $users->getSelections());
		self::assertSame([
			['posts', true, true, null],
		], $this->selectionState($users));
	}

	public function testNestedRelationSelectionAddsAncestorsOnce(): void
	{
		$users = $this->makeQuery('users');

		$users
			->select($users->posts->author)
			->select($users->posts);

		self::assertSame([
			['posts', true, true, null],
			['posts.author', true, true, null],
		], $this->selectionState($users));
	}

	public function testNestedRelationSelectionDefaultsIntermediateSegmentsToVisibleStructuralTraversal(): void
	{
		$users = $this->makeQuery('users');
		$users->select($users->posts->author);

		self::assertSame([
			['posts', false, true, null],
			['posts.author', true, true, null],
		], $this->selectionState($users));
	}

	public function testNestedRelationSelectionMergesToTheStrongestPathOptions(): void
	{
		$users = $this->makeQuery('users');

		$users->select(
			$users->posts->hidden()->author,
			$users->posts,
		);

		self::assertSame([
			['posts', true, true, null],
			['posts.author', true, true, null],
		], $this->selectionState($users));
	}

	public function testRepeatedRelationSelectionsUnionRequestedFieldsInStableOrder(): void
	{
		$users = $this->makeQuery('users');

		$users->select(
			$users->posts->fields('id'),
			$users->posts->fields('title', 'id'),
		);

		self::assertSame([
			['posts', true, true, ['id', 'title']],
		], $this->selectionState($users));
	}

	public function testUnrestrictedRepeatedRelationSelectionDominatesRestrictedFields(): void
	{
		$users = $this->makeQuery('users');

		$users->select(
			$users->posts->fields('title'),
			$users->posts,
		);

		self::assertSame([
			['posts', true, true, null],
		], $this->selectionState($users));
	}

	public function testHiddenTerminalRelationSelectionIsRejected(): void
	{
		$users = $this->makeQuery('users');

		$this->expectException(RelationSelectionException::class);
		$users->select($users->posts->hidden());
	}

	public function testConfiguredParentKeepsItsSelectionStateWhenTraversingChildren(): void
	{
		$users = $this->makeQuery('users');
		$configuredPosts = $users->posts->fields('id', 'title');
		$comments = $configuredPosts->comments->fields('id', 'body');

		self::assertSame(['id', 'title'], $configuredPosts->getFields());
		self::assertSame(['posts', 'comments'], $comments->getPath());
		self::assertSame(['id', 'body'], $comments->getFields());

		$users->select($configuredPosts, $comments);

		self::assertSame([
			['posts', true, true, ['id', 'title']],
			['posts.comments', true, true, ['id', 'body']],
		], $this->selectionState($users));
	}

	public function testRelationRefsAreNotCallable(): void
	{
		$users = $this->makeQuery('users');

		$this->expectException(\Error::class);
		$users->posts();
	}

	public function testNestedRelationNamedLikeConfigurationMethodResolvesThroughPropertyAccess(): void
	{
		$users = $this->makeQuery('users');
		$relation = $users->posts->fields;

		self::assertInstanceOf(RelationRef::class, $relation);
		self::assertSame(['posts', 'fields'], $relation->getPath());
		self::assertSame('postFields', $relation->getCollection()->getName());
	}

	public function testNestedFieldNamedLikeGetterMethodResolvesThroughPropertyAccess(): void
	{
		$users = $this->makeQuery('users');
		$field = $users->posts->getRelation;

		self::assertInstanceOf(FieldRef::class, $field);
		self::assertSame(['posts', 'getRelation'], $field->getPath());
		self::assertSame('getRelation', $field->getField()->getName());
	}

	public function testFieldsRejectFieldRefsFromAnotherQueryEvenWithTheSamePath(): void
	{
		$registry = $this->makeRegistry();
		$users = $this->makeQuery('users', $registry);
		$otherUsers = $this->makeQuery('users', $registry);

		$this->expectException(RelationSelectionException::class);
		$users->posts->fields($otherUsers->posts->title);
	}

	public function testSelectingAForeignRelationIsRejected(): void
	{
		$registry = $this->makeRegistry();
		$users = $this->makeQuery('users', $registry);
		$other = $this->makeQuery('users', $registry);

		$this->expectException(RelationSelectionException::class);
		$users->select($other->posts);
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
		$posts->field('getRelation', 'string');
		$posts->relation('author', CustomRelation::class)->collection('users');
		$posts->relation('comments', CustomRelation::class)->collection('comments');
		$posts->relation('fields', CustomRelation::class)->collection('postFields');

		$comments = $registry->collection('comments');
		$comments->field('id', 'int');
		$comments->field('body', 'string');

		$postFields = $registry->collection('postFields');
		$postFields->field('id', 'int');

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

	private function makeQuery(string $collection, ?Registry $registry = null): SelectQuery
	{
		$registry ??= $this->makeRegistry();

		return query($registry->getCollection($collection));
	}

	/**
	 * @return list<array{0: string, 1: bool, 2: bool, 3: ?array}>
	 */
	private function selectionState(SelectQuery $query): array
	{
		return array_map(
			static fn ($selection): array => [
				implode('.', $selection->getPath()),
				$selection->isLoaded(),
				$selection->isVisible(),
				$selection->getFields(),
			],
			$query->getRelationSelections()->getAll(),
		);
	}
}
