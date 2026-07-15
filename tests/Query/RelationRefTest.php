<?php

declare(strict_types=1);

namespace Tests\ON\Data\Query;

use Error;
use InvalidArgumentException;
use ON\Data\Definition\Registry;
use ON\Data\Query\Condition\ComparisonCondition;
use ON\Data\Query\Condition\InCondition;
use ON\Data\Query\Condition\LogicalCondition;
use ON\Data\Query\Condition\NotCondition;
use ON\Data\Query\Exception\RelationSelectionException;
use ON\Data\Query\Exception\UnknownQueryFieldException;
use ON\Data\Query\Exception\UnknownQueryMemberException;
use ON\Data\Query\Exception\UnknownQueryRelationException;
use ON\Data\Query\Expression\AggregateExpression;
use ON\Data\Query\Expression\FieldRef;
use ON\Data\Query\Expression\LiteralExpression;
use ON\Data\Query\Expression\ValueExpressionInterface;
use ON\Data\Query\Expression\ValueOperationExpression;
use function ON\Data\Query\query;
use ON\Data\Query\Relation\LoadStrategy;
use ON\Data\Query\Relation\RelationRef;
use ON\Data\Query\Selection\SelectionTag;
use ON\Data\Query\SelectQuery;
use function ON\Data\Query\x;
use PHPUnit\Framework\TestCase;
use stdClass;
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
		self::assertSame($users->getCollection()->getRelation('posts'), $posts->getDefinition());
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
		self::assertSame('posts.title', $title->getSelectionKey());
		self::assertSame($title, $users->posts->title);
	}

	public function testNestedRelationTraversalRetainsRootQueryAndPath(): void
	{
		$users = $this->makeQuery('users');
		$author = $users->posts->author;
		$name = $author->name;

		self::assertSame(['posts', 'author'], $author->getPath());
		self::assertSame(['posts', 'author', 'name'], $name->getPath());
		self::assertSame('posts.author.name', $name->getSelectionKey());
		self::assertSame($users, $author->getQuery());
		self::assertSame($users, $name->getQuery());
	}

	public function testNestedCacheIdentityMatchesExplicitLookup(): void
	{
		$users = $this->makeQuery('users');

		self::assertSame($users->posts->author, $users->posts->relation('author'));
		self::assertSame($users->posts->author->name, $users->posts->author->field('name'));
	}

	public function testRelationConfigurationMethodsMutateCachedRefsAndNormalizeRequestedFields(): void
	{
		$users = $this->makeQuery('users');
		$default = $users->posts;
		$fields = $default->fields('id', 'title', 'id');

		self::assertTrue($default->isLoaded());
		self::assertTrue($default->isVisible());
		self::assertSame(['id', 'title'], $fields->getFields());
		self::assertSame($default, $users->posts);
		self::assertSame($default, $fields);
	}

	public function testFieldsAcceptListsAndSamePathFieldRefs(): void
	{
		$users = $this->makeQuery('users');
		$fromArray = $users->posts->fields(['title']);
		$fromRefs = $users->posts->fields($users->posts->id, $users->posts->title);

		self::assertSame(['id', 'title'], $fromArray->getFields());
		self::assertSame(['id', 'title'], $fromRefs->getFields());
		self::assertSame(['id', 'title'], $users->posts->getFields());
	}

	public function testFieldsRejectInvalidSelectionInputs(): void
	{
		$users = $this->makeQuery('users');

		foreach ([
			static fn () => $users->posts->fields(),
			static fn () => $users->posts->fields(''),
			static fn () => $users->posts->fields(['id' => 'title']),
			static fn () => $users->posts->fields([new stdClass()]),
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

		$selections = $users->getSelections()->getAll();
		self::assertCount(1, $selections);
		self::assertTrue($selections[0]->hasTag(SelectionTag::DEFAULT));
		self::assertSame([], $users->getConditions());
		self::assertSame([], $users->getGroups());
		self::assertSame([], $users->getSorts());
	}

	public function testRelationRefIsNotAValueExpressionAndSelectLoadsVisibleFields(): void
	{
		$users = $this->makeQuery('users');

		self::assertFalse(is_a($users->posts, ValueExpressionInterface::class));

		$users->select($users->posts);

		self::assertTrue($users->posts->isSelected());
		self::assertTrue($users->posts->isLoaded());
		self::assertNull($users->posts->getFields());
		self::assertSame([
			['posts', true, true, null],
		], $this->selectionState($users));

		$selections = $users->getSelections()->getAll();
		self::assertCount(1, $selections);
		self::assertTrue($selections[0]->hasTag(SelectionTag::DEFAULT));
	}

	public function testSelectWithRelationAndScalarsKeepsConfiguredRelationFields(): void
	{
		$users = $this->makeQuery('users');

		$users->select($users->id, $users->posts->fields('id', 'title'));

		self::assertSame([
			['posts', true, true, ['id', 'title']],
		], $this->selectionState($users));
		self::assertFalse($users->getSelections()->getAll()[0]->hasTag(SelectionTag::DEFAULT));
	}

	public function testSelectQueryLoadNoLongerExists(): void
	{
		self::assertFalse(method_exists(SelectQuery::class, 'load'));
	}

	public function testConfiguringRootRelationDirectlyRegistersSelection(): void
	{
		$users = $this->makeQuery('users');

		$users->posts->fields('id', 'title');

		$selections = $users->getSelections()->getAll();
		self::assertCount(1, $selections);
		self::assertTrue($selections[0]->hasTag(SelectionTag::DEFAULT));
		self::assertSame([
			['posts', true, true, ['id', 'title']],
		], $this->selectionState($users));
	}

	public function testRelationLoadMarksRootRelationSelected(): void
	{
		$users = $this->makeQuery('users');

		$users->posts->load();

		self::assertTrue($users->posts->isLoaded());
		self::assertSame([
			['posts', true, true, null],
		], $this->selectionState($users));
	}

	public function testRelationLoadReturnsSameCachedRef(): void
	{
		$users = $this->makeQuery('users');

		self::assertSame($users->posts, $users->posts->load());
		self::assertSame($users->posts, $users->relation('posts'));
	}

	public function testRelationLoadDoesNotAlterOptionsOrVisibility(): void
	{
		$users = $this->makeQuery('users');

		$users->posts->load();

		self::assertNull($users->posts->getFields());
		self::assertSame([], $users->posts->getConditions());
		self::assertSame([], $users->posts->getSorts());
		self::assertNull($users->posts->getStrategy());
		self::assertTrue($users->posts->isVisible());
	}

	public function testRelationLoadIsIdempotent(): void
	{
		$users = $this->makeQuery('users');

		$users->posts->load()->load();

		self::assertSame([
			['posts', true, true, null],
		], $this->selectionState($users));
	}

	public function testRootAliasCollisionIsRejectedWhenRelationIsConfiguredAfterScalarSelection(): void
	{
		$users = $this->makeQuery('users');
		$users->select($users->name->as('posts'));
		$users->posts->fields('id', 'title');

		$this->expectException(RelationSelectionException::class);
		$users->getRelationSelections();
	}

	public function testNestedConfiguredRelationIsCollectedWithParentBranch(): void
	{
		$users = $this->makeQuery('users');
		$users->posts->author->fields('name');

		self::assertSame([
			['posts', false, true, null],
			['posts.author', true, true, ['name']],
		], $this->selectionState($users));
	}

	public function testNestedRelationLoadIsCollectedWithParentBranch(): void
	{
		$users = $this->makeQuery('users');
		$users->posts->author->load();

		self::assertSame([
			['posts', false, true, null],
			['posts.author', true, true, null],
		], $this->selectionState($users));
	}

	public function testRelationWhereStoresConditionsOnCachedRef(): void
	{
		$users = $this->makeQuery('users');
		$first = x()->eq($users->posts->published, true);
		$second = x()->eq($users->posts->title, 'Hello');
		$configured = $users->posts->where($first)->where($second);

		self::assertSame($users->posts, $configured);
		self::assertSame([$first, $second], $configured->getConditions());
		self::assertTrue($configured->isLoaded());
	}

	public function testRelationWhereRejectsEmptyConditionLists(): void
	{
		$users = $this->makeQuery('users');

		$this->expectException(InvalidArgumentException::class);
		$users->posts->where();
	}

	public function testRelationOrderByStoresSortsOnCachedRef(): void
	{
		$users = $this->makeQuery('users');
		$first = $users->posts->title->asc();
		$second = $users->posts->id->desc();
		$configured = $users->posts->orderBy($first)->orderBy($second);

		self::assertSame($users->posts, $configured);
		self::assertSame([$first, $second], $configured->getSorts());
		self::assertTrue($configured->isLoaded());
	}

	public function testRelationOrderByRejectsEmptySortLists(): void
	{
		$users = $this->makeQuery('users');

		$this->expectException(InvalidArgumentException::class);
		$users->posts->orderBy();
	}

	public function testRelationLimitAndOffsetStoreSelectionStateOnCachedRef(): void
	{
		$users = $this->makeQuery('users');

		self::assertSame($users->posts, $users->posts->limit(3)->offset(2));
		self::assertSame(3, $users->posts->getLimit());
		self::assertSame(2, $users->posts->getOffset());
		self::assertTrue($users->posts->hasOffset());
		self::assertTrue($users->posts->isLoaded());

		$selection = $users->getRelationSelections()->getAll()[0];
		self::assertSame(3, $selection->getLimit());
		self::assertSame(2, $selection->getOffset());
		self::assertTrue($selection->hasOffset());
	}

	public function testRelationLimitAndOffsetValidationRejectInvalidValues(): void
	{
		$users = $this->makeQuery('users');

		foreach ([
			static fn () => $users->posts->limit(0),
			static fn () => $users->posts->limit(-1),
			static fn () => $users->posts->offset(-1),
		] as $call) {
			try {
				$call();
				self::fail('Expected invalid relation pagination option to throw.');
			} catch (RelationSelectionException) {
				self::assertTrue(true);
			}
		}
	}

	public function testRelationStrategyHelpersStoreExplicitStrategyOnCachedRef(): void
	{
		$users = $this->makeQuery('users');

		self::assertSame($users->posts, $users->posts->strategy(LoadStrategy::JOIN));
		self::assertSame(LoadStrategy::JOIN, $users->posts->getStrategy());
		self::assertSame($users->posts, $users->posts->separate());
		self::assertSame(LoadStrategy::SEPARATE_QUERY, $users->posts->getStrategy());
		self::assertSame($users->posts, $users->posts->strategy(null));
		self::assertNull($users->posts->getStrategy());
		self::assertSame(LoadStrategy::JOIN, $users->posts->join()->getStrategy());
	}

	public function testItMergesRepeatedSelectionsOfTheSameLogicalRelationPath(): void
	{
		$users = $this->makeQuery('users');

		$users->posts->author->separate();
		$users->posts->separate();

		self::assertSame([
			['posts', true, true, null],
			['posts.author', true, true, null],
		], $this->selectionState($users));
	}

	public function testNestedSelectionsKeepIntermediateBranchesVisibleButStructuralByDefault(): void
	{
		$users = $this->makeQuery('users');
		$users->posts->author->separate();

		self::assertSame([
			['posts', false, true, null],
			['posts.author', true, true, null],
		], $this->selectionState($users));
	}

	public function testVisibleSelectionsOverrideHiddenTraversalOnTheSameRelationPath(): void
	{
		$users = $this->makeQuery('users');

		$users->posts->hidden()->author->separate();
		$users->posts->visible()->separate();

		self::assertSame([
			['posts', true, true, null],
			['posts.author', true, true, null],
		], $this->selectionState($users));
	}

	public function testRepeatedFieldSelectionsAreDeduplicatedInStableOrder(): void
	{
		$users = $this->makeQuery('users');

		$users->posts->fields('id');
		$users->posts->fields('title', 'id');

		self::assertSame([
			['posts', true, true, ['title', 'id']],
		], $this->selectionState($users));
	}

	public function testRepeatedSelectionsAppendConditionsAndSortsInStableOrder(): void
	{
		$users = $this->makeQuery('users');
		$firstCondition = x()->eq($users->posts->published, true);
		$secondCondition = x()->eq($users->posts->title, 'Hello');
		$firstSort = $users->posts->title->asc();
		$secondSort = $users->posts->id->desc();

		$users->posts->fields('id')->where($firstCondition)->orderBy($firstSort);
		$users->posts->fields('title')->where($secondCondition)->orderBy($secondSort);

		$selection = $users->getRelationSelections()->getAll()[0];
		self::assertSame(['title'], $selection->getFields());
		self::assertSame([$firstCondition, $secondCondition], $selection->getConditions());
		self::assertSame([$firstSort, $secondSort], $selection->getSorts());
	}

	public function testParentConditionsAreNotDuplicatedWhenSelectedParentAndChildAreCollected(): void
	{
		$users = $this->makeQuery('users');
		$condition = x()->eq($users->posts->published, true);

		$users->posts->where($condition)->comments->fields('id');

		$selection = $users->getRelationSelections()->getAll()[0];
		self::assertSame(['posts'], $selection->getPath());
		self::assertSame([$condition], $selection->getConditions());
	}

	public function testParentSortsAreNotDuplicatedWhenSelectedParentAndChildAreCollected(): void
	{
		$users = $this->makeQuery('users');
		$sort = $users->posts->title->asc();

		$users->posts->orderBy($sort)->comments->fields('id');

		$selection = $users->getRelationSelections()->getAll()[0];
		self::assertSame(['posts'], $selection->getPath());
		self::assertSame([$sort], $selection->getSorts());
	}

	public function testRepeatedStrategyCallsUseLatestCall(): void
	{
		$users = $this->makeQuery('users');

		$users->posts->join()->separate();

		self::assertSame(LoadStrategy::SEPARATE_QUERY, $users->getRelationSelections()->getAll()[0]->getStrategy());
	}

	public function testRepeatedSelectionsUseLatestLimitAndOffsetValues(): void
	{
		$users = $this->makeQuery('users');

		$users->posts->limit(2)->offset(5);
		$users->posts->limit(4)->offset(0);

		$selection = $users->getRelationSelections()->getAll()[0];
		self::assertSame(4, $selection->getLimit());
		self::assertSame(0, $selection->getOffset());
		self::assertTrue($selection->hasOffset());
	}

	public function testStrategyConfigurationKeepsExistingFieldList(): void
	{
		$users = $this->makeQuery('users');

		$users->posts->fields('title')->separate();

		self::assertSame([
			['posts', true, true, ['title']],
		], $this->selectionState($users));
	}

	public function testItRejectsHiddenLoadedTerminalRelations(): void
	{
		$users = $this->makeQuery('users');

		$this->expectException(RelationSelectionException::class);
		$users->posts->fields('id')->hidden();
	}

	public function testSelectedRelationCannotBeHidden(): void
	{
		$users = $this->makeQuery('users');

		$this->expectException(RelationSelectionException::class);
		$users->posts->separate()->hidden();
	}

	public function testHiddenRelationCannotBeSelectedByFields(): void
	{
		$users = $this->makeQuery('users');

		$this->expectException(RelationSelectionException::class);
		$users->posts->hidden()->fields('id');
	}

	public function testHiddenRelationCannotBeLoaded(): void
	{
		$users = $this->makeQuery('users');

		try {
			$users->posts->hidden()->load();
			self::fail('Expected hidden load to throw.');
		} catch (RelationSelectionException) {
			self::assertFalse($users->posts->isLoaded());
			self::assertSame([], $users->getRelationSelections()->getAll());
		}
	}

	public function testHiddenRelationCannotBeSelectedByWhere(): void
	{
		$users = $this->makeQuery('users');
		$condition = x()->eq($users->posts->published, true);

		try {
			$users->posts->hidden()->where($condition);
			self::fail('Expected hidden where to throw.');
		} catch (RelationSelectionException) {
			self::assertSame([], $users->posts->getConditions());
			self::assertFalse($users->posts->isLoaded());
		}
	}

	public function testHiddenRelationCannotBeSelectedByOrderBy(): void
	{
		$users = $this->makeQuery('users');
		$sort = $users->posts->title->asc();

		try {
			$users->posts->hidden()->orderBy($sort);
			self::fail('Expected hidden orderBy to throw.');
		} catch (RelationSelectionException) {
			self::assertSame([], $users->posts->getSorts());
			self::assertFalse($users->posts->isLoaded());
		}
	}

	public function testHiddenRelationCannotBeSelectedByLimitOrOffset(): void
	{
		$users = $this->makeQuery('users');

		try {
			$users->posts->hidden()->limit(2);
			self::fail('Expected hidden limit to throw.');
		} catch (RelationSelectionException) {
			self::assertNull($users->posts->getLimit());
			self::assertFalse($users->posts->isLoaded());
		}

		try {
			$users->posts->hidden()->offset(1);
			self::fail('Expected hidden offset to throw.');
		} catch (RelationSelectionException) {
			self::assertSame(0, $users->posts->getOffset());
			self::assertFalse($users->posts->hasOffset());
			self::assertFalse($users->posts->isLoaded());
		}
	}

	public function testHiddenRelationCannotBeSelectedByJoinOrSeparate(): void
	{
		$joined = $this->makeQuery('users');

		try {
			$joined->posts->hidden()->join();
			self::fail('Expected hidden join to throw.');
		} catch (RelationSelectionException) {
			self::assertNull($joined->posts->getStrategy());
			self::assertFalse($joined->posts->isLoaded());
		}

		$separate = $this->makeQuery('users');

		try {
			$separate->posts->hidden()->separate();
			self::fail('Expected hidden separate to throw.');
		} catch (RelationSelectionException) {
			self::assertNull($separate->posts->getStrategy());
			self::assertFalse($separate->posts->isLoaded());
		}
	}

	public function testFailedHiddenStrategyLeavesStrategyNull(): void
	{
		$users = $this->makeQuery('users');

		try {
			$users->posts->hidden()->strategy(LoadStrategy::JOIN);
			self::fail('Expected hidden strategy to throw.');
		} catch (RelationSelectionException) {
			self::assertNull($users->posts->getStrategy());
			self::assertFalse($users->posts->isLoaded());
		}
	}

	public function testFailedHiddenOptionsDoNotLeakAfterRelationIsMadeVisibleAndSelected(): void
	{
		$users = $this->makeQuery('users');
		$failedCondition = x()->eq($users->posts->published, true);
		$failedSort = $users->posts->title->asc();

		foreach ([
			static fn () => $users->posts->where($failedCondition),
			static fn () => $users->posts->orderBy($failedSort),
			static fn () => $users->posts->limit(1),
			static fn () => $users->posts->offset(1),
			static fn () => $users->posts->strategy(LoadStrategy::JOIN),
		] as $attempt) {
			try {
				$users->posts->hidden();
				$attempt();
				self::fail('Expected hidden selection attempt to throw.');
			} catch (RelationSelectionException) {
				$users->posts->visible();
			}
		}

		$users->posts->fields('id');

		$selection = $users->getRelationSelections()->getAll()[0];
		self::assertSame(['id'], $selection->getFields());
		self::assertSame([], $selection->getConditions());
		self::assertSame([], $selection->getSorts());
		self::assertNull($selection->getLimit());
		self::assertSame(0, $selection->getOffset());
		self::assertFalse($selection->hasOffset());
		self::assertNull($selection->getStrategy());
	}

	public function testStrategyNullOnUnselectedRelationDoesNotSelectIt(): void
	{
		$users = $this->makeQuery('users');

		$users->posts->strategy(null);

		self::assertFalse($users->posts->isLoaded());
		self::assertSame([], $users->getRelationSelections()->getAll());
	}

	public function testStrategyNullOnHiddenUnselectedRelationDoesNotSelectIt(): void
	{
		$users = $this->makeQuery('users');

		$users->posts->hidden()->strategy(null);

		self::assertFalse($users->posts->isLoaded());
		self::assertNull($users->posts->getStrategy());
		self::assertSame([], $users->getRelationSelections()->getAll());
	}

	public function testStrategyNullAfterSeparateKeepsRelationSelectedAndClearsExplicitStrategy(): void
	{
		$users = $this->makeQuery('users');

		$users->posts->separate()->strategy(null);

		self::assertTrue($users->posts->isLoaded());
		self::assertNull($users->posts->getStrategy());
		self::assertCount(1, $users->getRelationSelections()->getAll());
	}

	public function testConfiguredParentFieldsRemainIntactWhenSelectingNestedChildren(): void
	{
		$users = $this->makeQuery('users');
		$configuredPosts = $users->posts->fields('id', 'title');
		$comments = $configuredPosts->comments->fields('id', 'body');

		self::assertSame(['id', 'title'], $configuredPosts->getFields());
		self::assertSame(['posts', 'comments'], $comments->getPath());
		self::assertSame(['id', 'body'], $comments->getFields());

		self::assertSame([
			['posts', true, true, ['id', 'title']],
			['posts.comments', true, true, ['id', 'body']],
		], $this->selectionState($users));
	}

	public function testRelationRefsAreNotCallable(): void
	{
		$users = $this->makeQuery('users');

		$this->expectException(Error::class);
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
		$this->expectExceptionMessage('belongs to a different SelectQuery');
		$users->select($other->posts);
	}

	public function testConditionBindToCopiesFieldsWithoutMutatingOriginalCondition(): void
	{
		$registry = $this->makeRegistry();
		$users = $this->makeQuery('users', $registry);
		$otherUsers = $this->makeQuery('users', $registry);
		$condition = $users->posts->title->eq('Hello');

		$bound = $condition->bindTo($otherUsers, from: $users);

		self::assertNotSame($condition, $bound);
		self::assertInstanceOf(ComparisonCondition::class, $condition);
		self::assertInstanceOf(ComparisonCondition::class, $bound);
		self::assertInstanceOf(FieldRef::class, $condition->getLeft());
		self::assertInstanceOf(FieldRef::class, $bound->getLeft());
		self::assertSame($users->posts, $condition->getLeft()->getSource());
		self::assertSame(['posts', 'title'], $condition->getLeft()->getPath());
		self::assertSame($otherUsers->posts, $bound->getLeft()->getSource());
		self::assertSame(['posts', 'title'], $bound->getLeft()->getPath());
	}

	public function testConditionBindToPreservesPathRelativeToRootSource(): void
	{
		$registry = $this->makeRegistry();
		$users = $this->makeQuery('users', $registry);
		$otherUsers = $this->makeQuery('users', $registry);
		$condition = $users->posts->title->eq('Hello');

		$bound = $condition->bindTo($otherUsers, from: $users);

		self::assertInstanceOf(ComparisonCondition::class, $bound);
		self::assertInstanceOf(FieldRef::class, $bound->getLeft());
		self::assertSame($otherUsers->posts, $bound->getLeft()->getSource());
		self::assertSame(['posts', 'title'], $bound->getLeft()->getPath());
	}

	public function testConditionBindToPreservesPathRelativeToRelationSource(): void
	{
		$registry = $this->makeRegistry();
		$users = $this->makeQuery('users', $registry);
		$posts = $this->makeQuery('posts', $registry);
		$condition = $users->posts->title->eq('Hello');

		$bound = $condition->bindTo($posts, from: $users->posts);

		self::assertInstanceOf(ComparisonCondition::class, $bound);
		self::assertInstanceOf(FieldRef::class, $bound->getLeft());
		self::assertSame($posts, $bound->getLeft()->getSource());
		self::assertSame(['title'], $bound->getLeft()->getPath());
	}

	public function testSortBindToPreservesPathRelativeToRelationSource(): void
	{
		$registry = $this->makeRegistry();
		$users = $this->makeQuery('users', $registry);
		$posts = $this->makeQuery('posts', $registry);
		$sort = $users->posts->createdAt->desc();

		$bound = $sort->bindTo($posts, from: $users->posts);

		self::assertNotSame($sort, $bound);
		self::assertInstanceOf(FieldRef::class, $bound->getExpression());
		self::assertSame($posts, $bound->getExpression()->getSource());
		self::assertSame(['createdAt'], $bound->getExpression()->getPath());
		self::assertSame($users->posts, $sort->getExpression()->getSource());
	}

	public function testBindToRecursivelyCopiesNestedConditionsAndValueExpressions(): void
	{
		$registry = $this->makeRegistry();
		$users = $this->makeQuery('users', $registry);
		$posts = $this->makeQuery('posts', $registry);
		$condition = x()->and(
			$users->posts->title->upper()->eq('HELLO'),
			x()->or(
				$users->posts->id->sum()->gt(1),
				$users->posts->published->eq(true),
			),
		);

		$bound = $condition->bindTo($posts, from: $users->posts);

		self::assertInstanceOf(LogicalCondition::class, $bound);
		$first = $bound->getConditions()[0];
		self::assertInstanceOf(ComparisonCondition::class, $first);
		self::assertInstanceOf(ValueOperationExpression::class, $first->getLeft());
		self::assertInstanceOf(FieldRef::class, $first->getLeft()->getArguments()[0]);
		self::assertSame($posts, $first->getLeft()->getArguments()[0]->getSource());
		self::assertSame(['title'], $first->getLeft()->getArguments()[0]->getPath());

		$second = $bound->getConditions()[1];
		self::assertInstanceOf(LogicalCondition::class, $second);
		self::assertInstanceOf(ComparisonCondition::class, $second->getConditions()[0]);
		$aggregate = $second->getConditions()[0]->getLeft();
		self::assertInstanceOf(AggregateExpression::class, $aggregate);
		self::assertInstanceOf(FieldRef::class, $aggregate->getExpression());
		self::assertSame($posts, $aggregate->getExpression()->getSource());
		self::assertSame(['id'], $aggregate->getExpression()->getPath());
		self::assertInstanceOf(LogicalCondition::class, $condition);
		self::assertInstanceOf(ComparisonCondition::class, $condition->getConditions()[0]);
		self::assertInstanceOf(ValueOperationExpression::class, $condition->getConditions()[0]->getLeft());
		self::assertInstanceOf(FieldRef::class, $condition->getConditions()[0]->getLeft()->getArguments()[0]);
		self::assertSame($users->posts, $condition->getConditions()[0]->getLeft()->getArguments()[0]->getSource());
	}

	public function testBindToRecursivelyCopiesNotConditionsAndInExpressionLists(): void
	{
		$registry = $this->makeRegistry();
		$users = $this->makeQuery('users', $registry);
		$posts = $this->makeQuery('posts', $registry);
		$condition = x()->not(x()->in(
			$users->posts->title,
			[
				$users->posts->title->upper(),
				x()->literal('Hello'),
			],
		));

		$bound = $condition->bindTo($posts, from: $users->posts);

		self::assertInstanceOf(NotCondition::class, $bound);
		self::assertInstanceOf(InCondition::class, $bound->getCondition());
		self::assertInstanceOf(FieldRef::class, $bound->getCondition()->getExpression());
		self::assertSame($posts, $bound->getCondition()->getExpression()->getSource());
		self::assertSame(['title'], $bound->getCondition()->getExpression()->getPath());

		$set = $bound->getCondition()->getSet();
		self::assertIsArray($set);
		self::assertInstanceOf(ValueOperationExpression::class, $set[0]);
		self::assertInstanceOf(FieldRef::class, $set[0]->getArguments()[0]);
		self::assertSame($posts, $set[0]->getArguments()[0]->getSource());
		self::assertSame(['title'], $set[0]->getArguments()[0]->getPath());

		self::assertInstanceOf(NotCondition::class, $condition);
		self::assertInstanceOf(InCondition::class, $condition->getCondition());
		self::assertInstanceOf(FieldRef::class, $condition->getCondition()->getExpression());
		self::assertSame($users->posts, $condition->getCondition()->getExpression()->getSource());
	}

	public function testWindowExpressionBindToRecursivelyBindsWindowSpec(): void
	{
		$registry = $this->makeRegistry();
		$users = $this->makeQuery('users', $registry);
		$posts = $this->makeQuery('posts', $registry);
		$rank = x()->fn()->rowNumber()->over(
			partitionBy: $users->posts->published,
			orderBy: $users->posts->title->desc(),
		);

		$bound = $rank->bindTo($posts, from: $users->posts);

		$partition = $bound->getWindow()->getPartitionBy()[0];
		$order = $bound->getWindow()->getOrderings()[0]->getExpression();
		self::assertInstanceOf(FieldRef::class, $partition);
		self::assertInstanceOf(FieldRef::class, $order);
		self::assertSame($posts, $partition->getSource());
		self::assertSame(['published'], $partition->getPath());
		self::assertSame($posts, $order->getSource());
		self::assertSame(['title'], $order->getPath());
		self::assertInstanceOf(FieldRef::class, $rank->getWindow()->getPartitionBy()[0]);
		self::assertSame($users->posts, $rank->getWindow()->getPartitionBy()[0]->getSource());
	}

	public function testBindConditionsBindsRelationFieldsToTargetQuery(): void
	{
		$registry = $this->makeRegistry();
		$users = $this->makeQuery('users', $registry);
		$posts = $this->makeQuery('posts', $registry);

		$posts->bindConditions($users->posts, x()->eq($users->posts->published, true));

		$condition = $posts->getConditions()[0];
		self::assertInstanceOf(ComparisonCondition::class, $condition);
		self::assertInstanceOf(FieldRef::class, $condition->getLeft());
		self::assertSame($posts, $condition->getLeft()->getSource());
		self::assertSame(['published'], $condition->getLeft()->getPath());
	}

	public function testBindConditionsBindsNestedRelationFieldsToTargetQuery(): void
	{
		$registry = $this->makeRegistry();
		$users = $this->makeQuery('users', $registry);
		$posts = $this->makeQuery('posts', $registry);

		$posts->bindConditions($users->posts, x()->eq($users->posts->author->name, 'Ada'));

		$condition = $posts->getConditions()[0];
		self::assertInstanceOf(ComparisonCondition::class, $condition);
		self::assertInstanceOf(FieldRef::class, $condition->getLeft());
		self::assertSame($posts->author, $condition->getLeft()->getSource());
		self::assertSame(['author', 'name'], $condition->getLeft()->getPath());
	}

	public function testBindSortsBindsSortExpressions(): void
	{
		$registry = $this->makeRegistry();
		$users = $this->makeQuery('users', $registry);
		$posts = $this->makeQuery('posts', $registry);

		$posts->bindSorts($users->posts, $users->posts->title->asc());

		$expression = $posts->getSorts()[0]->getExpression();
		self::assertInstanceOf(FieldRef::class, $expression);
		self::assertSame($posts, $expression->getSource());
		self::assertSame(['title'], $expression->getPath());
	}

	public function testCompositeExpressionsPreserveStructureWhileBindingNestedFields(): void
	{
		$registry = $this->makeRegistry();
		$users = $this->makeQuery('users', $registry);
		$posts = $this->makeQuery('posts', $registry);

		$posts->bindConditions(
			$users->posts,
			x()->eq($users->posts->id->sum(), $users->posts->title->upper()),
		);

		$condition = $posts->getConditions()[0];
		self::assertInstanceOf(ComparisonCondition::class, $condition);
		self::assertInstanceOf(AggregateExpression::class, $condition->getLeft());
		self::assertInstanceOf(FieldRef::class, $condition->getLeft()->getExpression());
		self::assertSame(['id'], $condition->getLeft()->getExpression()->getPath());
		self::assertInstanceOf(ValueOperationExpression::class, $condition->getRight());
		self::assertInstanceOf(FieldRef::class, $condition->getRight()->getArguments()[0]);
		self::assertSame(['title'], $condition->getRight()->getArguments()[0]->getPath());
	}

	public function testLeafExpressionsAndFieldsOutsideSourceAreNotBound(): void
	{
		$registry = $this->makeRegistry();
		$users = $this->makeQuery('users', $registry);
		$posts = $this->makeQuery('posts', $registry);
		$literal = x()->literal('Ada');

		self::assertInstanceOf(LiteralExpression::class, $literal);
		self::assertSame($literal, $literal->bindTo($posts, from: $users->posts));
		self::assertSame($users->name, $users->name->bindTo($posts, from: $users->posts));
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
		$posts->field('createdAt', 'datetime');
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
