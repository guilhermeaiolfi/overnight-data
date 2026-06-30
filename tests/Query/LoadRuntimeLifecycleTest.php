<?php

declare(strict_types=1);

namespace Tests\ON\Data\Query;

use ON\Data\Database\QueryExecutorInterface;
use ON\Data\Definition\Registry;
use ON\Data\Query\Exception\LoadRuntimeException;
use ON\Data\Query\Relation\Loader\AbstractLoader;
use ON\Data\Query\Relation\LoadRuntime;
use ON\Data\Query\Relation\LoadStrategy;
use ON\Data\Query\Relation\RelationLoadBranch;
use ON\Data\Query\Relation\RootLoadBranch;
use ON\Data\Query\Result\Parser\AbstractNode;
use ON\Data\Query\Result\Parser\CollectionNode;
use ON\Data\Query\Result\Parser\RootNode;
use ON\Data\Query\Result\Parser\SingularNode;
use ON\Data\Query\Selection\SelectionList;
use ON\Data\Query\Selection\SelectionReason;
use ON\Data\Query\SelectQuery;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

final class LoadRuntimeLifecycleTest extends TestCase
{
	protected function tearDown(): void
	{
		LifecycleEvents::$events = [];
		LifecycleEvents::$referenceSnapshots = [];
		LifecycleEvents::$returnedNodes = [];
		LifecycleEvents::$registerColumns = [];
		LifecycleEvents::$loadCalls = 0;
		LifecycleEvents::$attachmentModes = [];
		LifecycleEvents::$initCalls = [];
		LifecycleEvents::$topLevelParentNodeIsRoot = false;
		LifecycleEvents::$plannedRootColumns = [];
	}

	public function testNamedContinuationRunsAfterParentRowsAreParsed(): void
	{
		$users = new SelectQuery($this->makeBasicRegistry(LifecycleRecordingLoader::class)->getCollection('users'), new LifecycleExecutor());
		$users->select($users->posts);

		$users->fetchAll();

		self::assertSame(['load:posts', 'initNode:posts', 'loadData:posts'], LifecycleEvents::$events);
		self::assertSame([['id' => 1]], LifecycleEvents::$referenceSnapshots[0]);
	}

	public function testDefaultContinuationRepeatsLoadOnTheSameBranch(): void
	{
		$users = new SelectQuery($this->makeBasicRegistry(RepeatLoadLifecycleLoader::class)->getCollection('users'), new LifecycleExecutor());
		$users->select($users->posts);

		$users->fetchAll();

		self::assertSame(2, LifecycleEvents::$loadCalls);
	}

	public function testInvalidScheduledMethodsAreRejected(): void
	{
		$users = new SelectQuery($this->makeBasicRegistry(InvalidScheduledMethodLoader::class)->getCollection('users'), new LifecycleExecutor());
		$users->select($users->posts);

		$this->expectException(LoadRuntimeException::class);
		$users->fetchAll();
	}

	public function testContinuationFromRegisterIsRejected(): void
	{
		$users = new SelectQuery($this->makeBasicRegistry(RegisterSchedulingLoader::class)->getCollection('users'), new LifecycleExecutor());
		$users->select($users->posts);

		$this->expectException(LoadRuntimeException::class);
		$users->fetchAll();
	}

	public function testRegisterRunsOnceAndReturnsTheNodeStoredOnTheBranch(): void
	{
		$users = new SelectQuery($this->makeBasicRegistry(LifecycleRecordingLoader::class)->getCollection('users'), new LifecycleExecutor());
		$users->select($users->posts);

		$users->fetchAll();

		self::assertCount(1, LifecycleEvents::$returnedNodes);
		self::assertInstanceOf(CollectionNode::class, LifecycleEvents::$returnedNodes[0]);
		self::assertSame(['posts' => 1], LifecycleEvents::$initCalls);
	}

	public function testAbstractLoaderRegisterRecursivelyRegistersChildBranchesBeforeParentNodeConstruction(): void
	{
		$users = new SelectQuery($this->makeNestedRegistry()->getCollection('users'), new NestedLifecycleExecutor());
		$users->select($users->posts->author);

		$this->prepareRuntime($users);

		self::assertSame([
			'initNode:author',
			'initNode:posts',
		], LifecycleEvents::$events);
	}

	public function testDescendantRequiredFieldsArePresentInParentParserNodeColumns(): void
	{
		$users = new SelectQuery($this->makeNestedRegistry()->getCollection('users'), new NestedLifecycleExecutor());
		$users->select($users->posts->author);
		$this->prepareRuntime($users);

		$columns = LifecycleEvents::$registerColumns['posts'];
		sort($columns);

		self::assertSame(['authorId', 'id', 'userId'], $columns);
	}

	public function testRequestedPublicFieldsRemainSeparateFromInternallyRequiredNodeColumns(): void
	{
		$users = new SelectQuery($this->makeBasicRegistry(LifecycleRecordingLoader::class)->getCollection('users'), new LifecycleExecutor());
		$users->select($users->posts->fields('title'));
		$runtime = $this->prepareRuntime($users);
		$branches = $this->readProperty($runtime, 'branches');
		$branch = array_values($branches)[0];

		self::assertSame(['title'], $branch->getPublicFields());
		self::assertSame(['title', 'id', 'userId'], $branch->getParserFields());
	}

	public function testRelationLoadBranchNoLongerKeepsLegacySelectionStateProperties(): void
	{
		$reflection = new \ReflectionClass(RelationLoadBranch::class);

		foreach (['parserFieldMap', 'publicFieldMap', 'parserFields', 'publicFieldOrder'] as $property) {
			self::assertFalse($reflection->hasProperty($property), $property);
		}
	}

	public function testTopLevelRelationBranchesUseTheRootBranchAsParent(): void
	{
		$users = new SelectQuery($this->makeBasicRegistry(LifecycleRecordingLoader::class)->getCollection('users'), new LifecycleExecutor());
		$users->select($users->posts);
		$runtime = $this->prepareRuntime($users);
		$branches = $this->readProperty($runtime, 'branches');
		$rootBranch = $this->readProperty($runtime, 'rootBranch');
		$branch = array_values($branches)[0];

		self::assertSame($rootBranch, $branch->getParent());
		self::assertInstanceOf(RootLoadBranch::class, $rootBranch);
		self::assertInstanceOf(RelationLoadBranch::class, $branch);
		self::assertSame([$branch], $rootBranch->getChildren());
	}

	public function testCompositePrimaryKeyDeduplicationStillWorksWhenPublicKeyFieldsAreOmitted(): void
	{
		$employees = new SelectQuery($this->makeCompositeDedupRegistry()->getCollection('employees'), new CompositeDedupExecutor());
		$employees->select($employees->tenantId, $employees->name, $employees->badges->fields('label'));

		self::assertSame([
			[
				'tenantId' => 1,
				'name' => 'Ada',
				'badges' => [
					['label' => 'Core'],
				],
			],
			[
				'tenantId' => 1,
				'name' => 'Grace',
				'badges' => [
					['label' => 'Core'],
				],
			],
		], $employees->fetchAll());
	}

	public function testRootFieldsRequiredDuringPlanningArePresentBeforeRootNodeConstruction(): void
	{
		$users = new SelectQuery($this->makeRootRequirementRegistry()->getCollection('users'), new LifecycleExecutor());
		$users->select($users->name, $users->posts);
		$runtime = $this->prepareRuntime($users);
		$rootBranch = $this->readProperty($runtime, 'rootBranch');
		$rootNode = $rootBranch->getNode();
		$columns = $this->readProperty($rootNode, 'columns');

		self::assertContains('name', $columns);
		self::assertContains('name', LifecycleEvents::$plannedRootColumns);
	}

	public function testExplicitRootFieldRemainsPublicWhilePrimaryKeyStaysInternal(): void
	{
		$users = new SelectQuery($this->makeBasicRegistry(ExecutingLifecycleLoader::class)->getCollection('users'), new ExplicitRootSelectionExecutor());
		$users->select($users->name, $users->posts);

		self::assertSame([[
			'name' => 'Ada',
			'posts' => [
				['id' => 10, 'userId' => 1, 'title' => 'Hello'],
			],
		]], $users->fetchAll());
	}

	public function testExplicitRootAliasedFieldIsPublicUnderItsAlias(): void
	{
		$users = new SelectQuery($this->makeBasicRegistry(ExecutingLifecycleLoader::class)->getCollection('users'), new AliasedRootSelectionExecutor());
		$users->select($users->name->as('title'), $users->posts);

		self::assertSame([[
			'title' => 'Ada',
			'posts' => [
				['id' => 10, 'userId' => 1, 'title' => 'Hello'],
			],
		]], $users->fetchAll());
	}

	public function testDefaultVisibleRootFieldsRemainPublicWhenNoExplicitRootFieldsAreSelected(): void
	{
		$users = new SelectQuery($this->makeBasicRegistry(ExecutingLifecycleLoader::class)->getCollection('users'), new LifecycleExecutor());
		$users->select($users->posts);

		self::assertSame([[
			'id' => 1,
			'name' => 'Ada',
			'posts' => [
				['id' => 10, 'userId' => 1, 'title' => 'Hello'],
			],
		]], $users->fetchAll());
	}

	public function testInternalRootRequiredFieldsAreSelectedButDoNotLeakIntoPublicOutput(): void
	{
		$users = new SelectQuery($this->makeHiddenRootRequirementRegistry()->getCollection('users'), new HiddenRootRequirementExecutor());
		$users->select($users->posts);
		$runtime = $this->prepareRuntime($users);
		$rootBranch = $this->readProperty($runtime, 'rootBranch');
		$columns = $this->readProperty($rootBranch->getNode(), 'columns');

		self::assertContains('__on_data_root_required_secret_0', $columns);
		self::assertSame([[
			'id' => 1,
			'name' => 'Ada',
			'posts' => [],
		]], $users->fetchAll());
	}

	public function testRootFieldRequirementsReuseAnExistingPublicAlias(): void
	{
		$users = new SelectQuery($this->makeRootRequirementRegistry()->getCollection('users'), new AliasedRootSelectionExecutor());
		$users->select($users->name->as('title'), $users->posts);
		$runtime = $this->prepareRuntime($users);
		$rootBranch = $this->readProperty($runtime, 'rootBranch');
		$columns = $this->readProperty($rootBranch->getNode(), 'columns');
		$selections = $this->readProperty($rootBranch, 'selections');
		$titleSelection = $selections->getPublicItems()[0];

		self::assertSame(['title', '__on_data_root_required_id_0'], $columns);
		self::assertSame(['title'], LifecycleEvents::$plannedRootColumns);
		self::assertTrue($titleSelection->hasReason(SelectionReason::PUBLIC));
		self::assertTrue($titleSelection->hasReason(SelectionReason::REQUIRED));
		self::assertSame([[
			'title' => 'Ada',
			'posts' => [],
		]], $users->fetchAll());
	}

	public function testJoinedAndLinkedAttachmentModesAreRecordedDuringLoadAndAppliedDuringRegistration(): void
	{
		$users = new SelectQuery($this->makeMixedAttachmentRegistry()->getCollection('users'), new MixedAttachmentExecutor());
		$users->select($users->profile, $users->posts);
		$runtime = $this->prepareRuntime($users);
		$rootNode = $this->readProperty($runtime, 'rootBranch')->getNode();
		$profileNode = $rootNode->getNode('profile');
		$postsNode = $rootNode->getNode('posts');

		self::assertSame([
			'profile' => true,
			'posts' => false,
		], LifecycleEvents::$attachmentModes);
		self::assertTrue($this->readProperty($profileNode, 'joined'));
		self::assertFalse($this->readProperty($postsNode, 'joined'));
	}

	public function testNestedCustomLoadersAttachJoinedAndLinkedChildrenWithoutCustomRegisterOverrides(): void
	{
		$users = new SelectQuery($this->makeNestedAttachmentRegistry()->getCollection('users'), new NestedAttachmentExecutor());
		$users->select($users->posts->author, $users->posts->comments);
		$runtime = $this->prepareRuntime($users);
		$rootNode = $this->readProperty($runtime, 'rootBranch')->getNode();
		$postsNode = $rootNode->getNode('posts');
		$authorNode = $postsNode->getNode('author');
		$commentsNode = $postsNode->getNode('comments');

		self::assertFalse($this->readProperty($postsNode, 'joined'));
		self::assertTrue($this->readProperty($authorNode, 'joined'));
		self::assertFalse($this->readProperty($commentsNode, 'joined'));
		self::assertSame([
			'author' => 1,
			'comments' => 1,
			'posts' => 1,
		], LifecycleEvents::$initCalls);
	}

	public function testExecuteKeepsParentInvocationContextUntilItSchedulesItsContinuation(): void
	{
		$users = new SelectQuery($this->makeMultiPassBoundaryRegistry()->getCollection('users'), new MultiPassBoundaryExecutor());
		$users->select($users->posts->comments);

		$users->fetchAll();

		self::assertSame([
			'load:posts',
			'load:comments',
			'initNode:comments',
			'initNode:posts',
			'loadPivot:posts:start',
			'loadPivot:posts:after-execute',
			'loadTargets:posts',
			'loadData:comments',
		], LifecycleEvents::$events);
	}

	public function testTopLevelRelationRegisterCanAccessTheRootParentNode(): void
	{
		$users = new SelectQuery($this->makeTopLevelParentNodeRegistry()->getCollection('users'), new LifecycleExecutor());
		$users->select($users->posts);

		$this->prepareRuntime($users);

		self::assertTrue(LifecycleEvents::$topLevelParentNodeIsRoot);
	}

	private function makeBasicRegistry(string $loader): Registry
	{
		$registry = new Registry();

		$users = $registry->collection('users');
		$users->field('id', 'int');
		$users->field('name', 'string');
		$users->primaryKey('id');
		$users->hasMany('posts', 'posts')
			->innerKey('id')
			->outerKey('userId')
			->loader($loader)
			->end();

		$posts = $registry->collection('posts');
		$posts->field('id', 'int');
		$posts->field('userId', 'int');
		$posts->field('title', 'string');
		$posts->primaryKey('id');

		return $registry;
	}

	private function makeNestedRegistry(): Registry
	{
		$registry = new Registry();

		$authors = $registry->collection('authors');
		$authors->field('id', 'int');
		$authors->field('name', 'string');
		$authors->primaryKey('id');

		$posts = $registry->collection('posts');
		$posts->field('id', 'int');
		$posts->field('userId', 'int');
		$posts->field('authorId', 'int');
		$posts->field('title', 'string');
		$posts->primaryKey('id');
		$posts->belongsTo('author', 'authors')->innerKey('authorId')->outerKey('id')->loader(NestedAuthorLoader::class)->end();

		$users = $registry->collection('users');
		$users->field('id', 'int');
		$users->field('name', 'string');
		$users->primaryKey('id');
		$users->hasMany('posts', 'posts')->innerKey('id')->outerKey('userId')->loader(NestedPostsLoader::class)->end();

		return $registry;
	}

	private function makeRootRequirementRegistry(): Registry
	{
		$registry = new Registry();

		$users = $registry->collection('users');
		$users->field('id', 'int');
		$users->field('name', 'string');
		$users->primaryKey('id');
		$users->hasMany('posts', 'posts')->innerKey('name')->outerKey('title')->loader(RootFieldRequirementLoader::class)->end();

		$posts = $registry->collection('posts');
		$posts->field('id', 'int');
		$posts->field('userId', 'int');
		$posts->field('title', 'string');
		$posts->primaryKey('id');

		return $registry;
	}

	private function makeHiddenRootRequirementRegistry(): Registry
	{
		$registry = new Registry();

		$users = $registry->collection('users');
		$users->field('id', 'int');
		$users->field('name', 'string');
		$users->field('secret', 'string')->hidden(true)->end();
		$users->primaryKey('id');
		$users->hasMany('posts', 'posts')->innerKey('secret')->outerKey('title')->loader(HiddenRootFieldRequirementLoader::class)->end();

		$posts = $registry->collection('posts');
		$posts->field('id', 'int');
		$posts->field('title', 'string');
		$posts->primaryKey('id');

		return $registry;
	}

	private function makeMixedAttachmentRegistry(): Registry
	{
		$registry = new Registry();

		$profiles = $registry->collection('profiles');
		$profiles->field('id', 'int');
		$profiles->field('userId', 'int');
		$profiles->field('bio', 'string');
		$profiles->primaryKey('id');

		$posts = $registry->collection('posts');
		$posts->field('id', 'int');
		$posts->field('userId', 'int');
		$posts->field('title', 'string');
		$posts->primaryKey('id');

		$users = $registry->collection('users');
		$users->field('id', 'int');
		$users->field('name', 'string');
		$users->primaryKey('id');
		$users->hasOne('profile', 'profiles')->innerKey('id')->outerKey('userId')->loader(JoinedProfileLoader::class)->end();
		$users->hasMany('posts', 'posts')->innerKey('id')->outerKey('userId')->loader(LinkedPostsLoader::class)->end();

		return $registry;
	}

	private function makeNestedAttachmentRegistry(): Registry
	{
		$registry = new Registry();

		$authors = $registry->collection('authors');
		$authors->field('id', 'int');
		$authors->field('name', 'string');
		$authors->primaryKey('id');

		$comments = $registry->collection('comments');
		$comments->field('id', 'int');
		$comments->field('postId', 'int');
		$comments->field('body', 'string');
		$comments->primaryKey('id');

		$posts = $registry->collection('posts');
		$posts->field('id', 'int');
		$posts->field('userId', 'int');
		$posts->field('authorId', 'int');
		$posts->field('title', 'string');
		$posts->primaryKey('id');
		$posts->belongsTo('author', 'authors')->innerKey('authorId')->outerKey('id')->loader(NestedAuthorLoader::class)->end();
		$posts->hasMany('comments', 'comments')->innerKey('id')->outerKey('postId')->loader(NestedCommentsLoader::class)->end();

		$users = $registry->collection('users');
		$users->field('id', 'int');
		$users->field('name', 'string');
		$users->primaryKey('id');
		$users->hasMany('posts', 'posts')->innerKey('id')->outerKey('userId')->loader(SeparateNestedPostsLoader::class)->end();

		return $registry;
	}

	private function makeMultiPassBoundaryRegistry(): Registry
	{
		$registry = new Registry();

		$comments = $registry->collection('comments');
		$comments->field('id', 'int');
		$comments->field('postId', 'int');
		$comments->field('body', 'string');
		$comments->primaryKey('id');

		$posts = $registry->collection('posts');
		$posts->field('id', 'int');
		$posts->field('userId', 'int');
		$posts->field('title', 'string');
		$posts->primaryKey('id');
		$posts->hasMany('comments', 'comments')->innerKey('id')->outerKey('postId')->loader(BoundaryChildLoader::class)->end();

		$users = $registry->collection('users');
		$users->field('id', 'int');
		$users->field('name', 'string');
		$users->primaryKey('id');
		$users->hasMany('posts', 'posts')->innerKey('id')->outerKey('userId')->loader(BoundaryParentLoader::class)->end();

		return $registry;
	}

	private function makeCompositeDedupRegistry(): Registry
	{
		$registry = new Registry();

		$badges = $registry->collection('employee_badges');
		$badges->field('tenantId', 'int');
		$badges->field('employeeName', 'string');
		$badges->field('label', 'string');
		$badges->primaryKey('tenantId', 'employeeName');

		$employees = $registry->collection('employees');
		$employees->field('tenantId', 'int');
		$employees->field('accountId', 'int');
		$employees->field('name', 'string');
		$employees->hasMany('badges', 'employee_badges')
			->innerKey(['tenantId', 'name'])
			->outerKey(['tenantId', 'employeeName'])
			->end();
		$employees->primaryKey('tenantId', 'name');

		return $registry;
	}

	private function makeTopLevelParentNodeRegistry(): Registry
	{
		return $this->makeBasicRegistry(TopLevelParentNodeLoader::class);
	}

	private function prepareRuntime(SelectQuery $query): LoadRuntime
	{
		$runtime = new LoadRuntime($query, new LifecycleExecutor());
		$method = new ReflectionMethod(LoadRuntime::class, 'prepare');
		$method->setAccessible(true);
		$method->invoke($runtime);

		return $runtime;
	}

	public function testRootBranchOwnsIdentityAliasesForRootPrimaryKey(): void
	{
		$users = new SelectQuery($this->makeCompositeDedupRegistry()->getCollection('employees'), new CompositeDedupExecutor());
		$users->select($users->tenantId, $users->name, $users->badges->fields('label'));
		$runtime = $this->prepareRuntime($users);
		$rootBranch = $this->readProperty($runtime, 'rootBranch');
		$selections = $this->readProperty($rootBranch, 'selections');
		$identityAliases = array_map(
			static fn (object $selection): string => $selection->getExpression() instanceof \ON\Data\Query\Expression\AliasedExpression
				? $selection->getExpression()->getAlias()
				: implode('.', $selection->getExpression()->getPath()),
			$selections->getIdentityItems(),
		);
		$rootIdentityFields = $this->readProperty($rootBranch->getRootNode(), 'identityFields');

		self::assertCount(2, $identityAliases);
		self::assertSame($identityAliases, $rootIdentityFields);
	}

	public function testRootPrimaryKeySelectionsAreTrackedAsIdentityItems(): void
	{
		$users = new SelectQuery($this->makeBasicRegistry(LifecycleRecordingLoader::class)->getCollection('users'), new LifecycleExecutor());
		$users->select($users->posts);
		$runtime = $this->prepareRuntime($users);
		$rootBranch = $this->readProperty($runtime, 'rootBranch');
		$selections = $this->readProperty($rootBranch, 'selections');

		self::assertInstanceOf(SelectionList::class, $selections);
		self::assertSame(['id'], array_map(
			static fn (object $selection): string => $selection->getExpression() instanceof \ON\Data\Query\Expression\AliasedExpression
				? $selection->getExpression()->getAlias()
				: implode('.', $selection->getExpression()->getPath()),
			$selections->getIdentityItems(),
		));
		self::assertTrue($selections->getIdentityItems()[0]->hasReason(SelectionReason::REQUIRED));
	}

	public function testInternalExplicitRootSelectionsDoNotBecomePublicOutput(): void
	{
		$users = new SelectQuery($this->makeBasicRegistry(ExecutingLifecycleLoader::class)->getCollection('users'), new InternalExplicitRootExecutor());
		$users->select($users->name->as('__on_data_manual_name'), $users->posts);

		self::assertSame([[
			'id' => 1,
			'name' => 'Ada',
			'posts' => [
				['id' => 10, 'userId' => 1, 'title' => 'Hello'],
			],
		]], $users->fetchAll());
	}

	private function readProperty(object $object, string $name): mixed
	{
		$property = new ReflectionProperty($object, $name);
		$property->setAccessible(true);

		return $property->getValue($object);
	}
}

final class LifecycleEvents
{
	/**
	 * @var list<string>
	 */
	public static array $events = [];

	/**
	 * @var list<list<array<string, scalar>>>
	 */
	public static array $referenceSnapshots = [];

	/**
	 * @var list<AbstractNode>
	 */
	public static array $returnedNodes = [];

	/**
	 * @var array<string, list<string>>
	 */
	public static array $registerColumns = [];

	public static int $loadCalls = 0;

	/**
	 * @var array<string, bool>
	 */
	public static array $attachmentModes = [];

	/**
	 * @var array<string, int>
	 */
	public static array $initCalls = [];

	public static bool $topLevelParentNodeIsRoot = false;

	/**
	 * @var list<string>
	 */
	public static array $plannedRootColumns = [];
}

final class LifecycleExecutor implements QueryExecutorInterface
{
	public function fetchAll(SelectQuery $query): array
	{
		return match ($query->getCollection()->getName()) {
			'users' => [['id' => 1, 'name' => 'Ada']],
			'posts' => [['id' => 10, 'userId' => 1, 'title' => 'Hello']],
			default => [],
		};
	}

	public function fetchOne(SelectQuery $query): ?array
	{
		return null;
	}

	public function iterate(SelectQuery $query): iterable
	{
		return [];
	}
}

final class ExplicitRootSelectionExecutor implements QueryExecutorInterface
{
	public function fetchAll(SelectQuery $query): array
	{
		return match ($query->getCollection()->getName()) {
			'users' => [[
				'name' => 'Ada',
				'__on_data_root_required_id_0' => 1,
			]],
			'posts' => [['id' => 10, 'userId' => 1, 'title' => 'Hello']],
			default => [],
		};
	}

	public function fetchOne(SelectQuery $query): ?array
	{
		return null;
	}

	public function iterate(SelectQuery $query): iterable
	{
		return [];
	}
}

final class AliasedRootSelectionExecutor implements QueryExecutorInterface
{
	public function fetchAll(SelectQuery $query): array
	{
		return match ($query->getCollection()->getName()) {
			'users' => [[
				'title' => 'Ada',
				'__on_data_root_required_id_0' => 1,
			]],
			'posts' => [['id' => 10, 'userId' => 1, 'title' => 'Hello']],
			default => [],
		};
	}

	public function fetchOne(SelectQuery $query): ?array
	{
		return null;
	}

	public function iterate(SelectQuery $query): iterable
	{
		return [];
	}
}

final class HiddenRootRequirementExecutor implements QueryExecutorInterface
{
	public function fetchAll(SelectQuery $query): array
	{
		return match ($query->getCollection()->getName()) {
			'users' => [[
				'id' => 1,
				'name' => 'Ada',
				'__on_data_root_required_secret_0' => 'shh',
			]],
			'posts' => [],
			default => [],
		};
	}

	public function fetchOne(SelectQuery $query): ?array
	{
		return null;
	}

	public function iterate(SelectQuery $query): iterable
	{
		return [];
	}
}

final class InternalExplicitRootExecutor implements QueryExecutorInterface
{
	public function fetchAll(SelectQuery $query): array
	{
		return match ($query->getCollection()->getName()) {
			'users' => [[
				'id' => 1,
				'name' => 'Ada',
				'__on_data_manual_name' => 'Ada',
			]],
			'posts' => [['id' => 10, 'userId' => 1, 'title' => 'Hello']],
			default => [],
		};
	}

	public function fetchOne(SelectQuery $query): ?array
	{
		return null;
	}

	public function iterate(SelectQuery $query): iterable
	{
		return [];
	}
}

final class NestedLifecycleExecutor implements QueryExecutorInterface
{
	public function fetchAll(SelectQuery $query): array
	{
		return match ($query->getCollection()->getName()) {
			'users' => [['id' => 1, 'name' => 'Ada']],
			'posts' => [[
				'id' => 10,
				'userId' => 1,
				'authorId' => 7,
				'title' => 'Hello',
				'__on_data_posts_author_id_0' => 7,
				'__on_data_posts_author_name_1' => 'Ana',
			]],
			default => [],
		};
	}

	public function fetchOne(SelectQuery $query): ?array
	{
		return null;
	}

	public function iterate(SelectQuery $query): iterable
	{
		return [];
	}
}

final class MixedAttachmentExecutor implements QueryExecutorInterface
{
	public function fetchAll(SelectQuery $query): array
	{
		return match ($query->getCollection()->getName()) {
			'users' => [[
				'name' => 'Ada',
				'__on_data_root_required_id_0' => 1,
				'__on_data_profile_id_1' => 50,
				'__on_data_profile_userid_2' => 1,
				'__on_data_profile_bio_3' => 'Bio',
			]],
			'posts' => [['id' => 10, 'userId' => 1, 'title' => 'Hello']],
			default => [],
		};
	}

	public function fetchOne(SelectQuery $query): ?array
	{
		return null;
	}

	public function iterate(SelectQuery $query): iterable
	{
		return [];
	}
}

final class NestedAttachmentExecutor implements QueryExecutorInterface
{
	public function fetchAll(SelectQuery $query): array
	{
		return match ($query->getCollection()->getName()) {
			'users' => [[
				'id' => 1,
				'name' => 'Ada',
			]],
			'posts' => [[
				'id' => 10,
				'userId' => 1,
				'authorId' => 7,
				'title' => 'Hello',
				'__on_data_posts_author_id_0' => 7,
				'__on_data_posts_author_name_1' => 'Ana',
			]],
			'comments' => [[
				'id' => 100,
				'postId' => 10,
				'body' => 'Hi',
			]],
			default => [],
		};
	}

	public function fetchOne(SelectQuery $query): ?array
	{
		return null;
	}

	public function iterate(SelectQuery $query): iterable
	{
		return [];
	}
}

final class MultiPassBoundaryExecutor implements QueryExecutorInterface
{
	public function fetchAll(SelectQuery $query): array
	{
		return match ($query->getCollection()->getName()) {
			'users' => [[
				'id' => 1,
				'name' => 'Ada',
			]],
			'posts' => [[
				'id' => 10,
				'userId' => 1,
				'title' => 'Hello',
			]],
			'comments' => [[
				'id' => 100,
				'postId' => 10,
				'body' => 'Hi',
			]],
			default => [],
		};
	}

	public function fetchOne(SelectQuery $query): ?array
	{
		return null;
	}

	public function iterate(SelectQuery $query): iterable
	{
		return [];
	}
}

final class CompositeDedupExecutor implements QueryExecutorInterface
{
	public function fetchAll(SelectQuery $query): array
	{
		return match ($query->getCollection()->getName()) {
			'employees' => [
				['tenantId' => 1, 'accountId' => 10, 'name' => 'Ada'],
				['tenantId' => 1, 'accountId' => 10, 'name' => 'Grace'],
			],
			'employee_badges' => [
				['tenantId' => 1, 'employeeName' => 'Ada', 'label' => 'Core'],
				['tenantId' => 1, 'employeeName' => 'Ada', 'label' => 'Core'],
				['tenantId' => 1, 'employeeName' => 'Grace', 'label' => 'Core'],
			],
			default => [],
		};
	}

	public function fetchOne(SelectQuery $query): ?array
	{
		return null;
	}

	public function iterate(SelectQuery $query): iterable
	{
		return [];
	}
}

abstract class LifecycleTestLoader extends AbstractLoader
{
	protected function initNode(RelationLoadBranch $branch, LoadRuntime $runtime): AbstractNode
	{
		$relation = $branch->getRelationRef();
		$definition = $relation->getDefinition();
		$parentBranch = $branch->getParent();
		$identity = $branch->requireFields($relation->getCollection()->getPrimaryKey());
		$child = $branch->requireFields($definition->getOuterKeys());
		$parent = $parentBranch->requireFields($definition->getInnerKeys());

		$node = new CollectionNode(
			$branch->getParserFields(),
			$identity,
			$child,
			$parent,
		);

		LifecycleEvents::$events[] = 'initNode:' . $relation->getName();
		LifecycleEvents::$initCalls[$relation->getName()] = (LifecycleEvents::$initCalls[$relation->getName()] ?? 0) + 1;
		LifecycleEvents::$returnedNodes[] = $node;
		LifecycleEvents::$registerColumns[$relation->getName()] = $branch->getParserFields();

		return $node;
	}

	protected function prepareSeparateQuery(RelationLoadBranch $branch, LoadRuntime $runtime): void
	{
		$relation = $branch->getRelationRef();
		$query = new SelectQuery($relation->getCollection(), new LifecycleExecutor());
		$branch->setJoinedAttachment(false);
		LifecycleEvents::$attachmentModes[$relation->getName()] = false;
		$runtime->setQueryContext($branch, $query, $query);
	}
}

final class LifecycleRecordingLoader extends LifecycleTestLoader
{
	public function load(RelationLoadBranch $branch, LoadRuntime $runtime): void
	{
		$relation = $branch->getRelationRef();
		LifecycleEvents::$events[] = 'load:' . $relation->getName();
		$this->prepareSeparateQuery($branch, $runtime);
		$runtime->continueWith($branch, 'loadData');
	}

	public function loadData(RelationLoadBranch $branch, LoadRuntime $runtime): void
	{
		$relation = $branch->getRelationRef();
		LifecycleEvents::$events[] = 'loadData:' . $relation->getName();
		LifecycleEvents::$referenceSnapshots[] = $branch->getReferenceValues();
	}
}

final class ExecutingLifecycleLoader extends LifecycleTestLoader
{
	public function load(RelationLoadBranch $branch, LoadRuntime $runtime): void
	{
		$this->prepareSeparateQuery($branch, $runtime);
		$runtime->continueWith($branch, 'loadData');
	}

	public function loadData(RelationLoadBranch $branch, LoadRuntime $runtime): void
	{
		$runtime->execute($branch, $branch->getQuery());
	}
}

final class RepeatLoadLifecycleLoader extends LifecycleTestLoader
{
	public function load(RelationLoadBranch $branch, LoadRuntime $runtime): void
	{
		LifecycleEvents::$loadCalls++;

		if (LifecycleEvents::$loadCalls === 1) {
			$this->prepareSeparateQuery($branch, $runtime);
			$runtime->continueWith($branch);
		}
	}
}

final class InvalidScheduledMethodLoader extends LifecycleTestLoader
{
	public function load(RelationLoadBranch $branch, LoadRuntime $runtime): void
	{
		$this->prepareSeparateQuery($branch, $runtime);
		$runtime->continueWith($branch, 'missingMethod');
	}
}

final class RegisterSchedulingLoader extends LifecycleTestLoader
{
	protected function initNode(RelationLoadBranch $branch, LoadRuntime $runtime): AbstractNode
	{
		$node = parent::initNode($branch, $runtime);
		$runtime->continueWith($branch, 'loadData');

		return $node;
	}

	public function load(RelationLoadBranch $branch, LoadRuntime $runtime): void
	{
		$this->prepareSeparateQuery($branch, $runtime);
	}

	public function loadData(RelationLoadBranch $branch, LoadRuntime $runtime): void
	{
	}
}

class NestedPostsLoader extends AbstractLoader
{
	protected function initNode(RelationLoadBranch $branch, LoadRuntime $runtime): AbstractNode
	{
		$relation = $branch->getRelationRef();
		$parentBranch = $branch->getParent();
		$identity = $branch->requireFields(['id']);
		$child = $branch->requireFields(['userId']);
		$parent = $parentBranch->requireFields(['id']);
		LifecycleEvents::$events[] = 'initNode:' . $relation->getName();
		LifecycleEvents::$initCalls[$relation->getName()] = (LifecycleEvents::$initCalls[$relation->getName()] ?? 0) + 1;
		LifecycleEvents::$registerColumns['posts'] = $branch->getParserFields();

		return new CollectionNode($branch->getParserFields(), $identity, $child, $parent);
	}

	public function load(RelationLoadBranch $branch, LoadRuntime $runtime): void
	{
		$relation = $branch->getRelationRef();
		$query = new SelectQuery($relation->getCollection(), new NestedLifecycleExecutor());
		$branch->setJoinedAttachment(false);
		$runtime->setQueryContext($branch, $query, $query);
		$runtime->continueWith($branch, 'loadData');
	}

	public function loadData(RelationLoadBranch $branch, LoadRuntime $runtime): void
	{
		$runtime->execute($branch, $branch->getQuery());
	}
}

final class NestedAuthorLoader extends AbstractLoader
{
	public function getDefaultLoadStrategy(): LoadStrategy
	{
		return LoadStrategy::JOIN;
	}

	protected function initNode(RelationLoadBranch $branch, LoadRuntime $runtime): AbstractNode
	{
		$relation = $branch->getRelationRef();
		$parentBranch = $branch->getParent();
		$identity = $branch->requireFields(['id']);
		$child = $branch->requireFields(['id']);
		$parent = $parentBranch->requireFields(['authorId']);
		LifecycleEvents::$events[] = 'initNode:' . $relation->getName();
		LifecycleEvents::$initCalls[$relation->getName()] = (LifecycleEvents::$initCalls[$relation->getName()] ?? 0) + 1;
		LifecycleEvents::$registerColumns['author'] = $branch->getParserFields();

		return new SingularNode($branch->getParserFields(), $identity, $child, $parent);
	}

	public function load(RelationLoadBranch $branch, LoadRuntime $runtime): void
	{
		$queryRelation = $runtime->getQueryRelation($branch);
		$branch->setJoinedAttachment(true);
		$runtime->setQueryContext($branch, $queryRelation->getQuery(), $this->join($queryRelation), $queryRelation);
	}
}

final class RootFieldRequirementLoader extends LifecycleTestLoader
{
	public function load(RelationLoadBranch $branch, LoadRuntime $runtime): void
	{
		LifecycleEvents::$plannedRootColumns = $branch->getParent()->requireFields(['name']);
		$this->prepareSeparateQuery($branch, $runtime);
	}
}

final class HiddenRootFieldRequirementLoader extends LifecycleTestLoader
{
	public function load(RelationLoadBranch $branch, LoadRuntime $runtime): void
	{
		LifecycleEvents::$plannedRootColumns = $branch->getParent()->requireFields(['secret']);
		$this->prepareSeparateQuery($branch, $runtime);
	}
}

final class JoinedProfileLoader extends AbstractLoader
{
	public function getDefaultLoadStrategy(): LoadStrategy
	{
		return LoadStrategy::JOIN;
	}

	protected function initNode(RelationLoadBranch $branch, LoadRuntime $runtime): AbstractNode
	{
		$relation = $branch->getRelationRef();
		$parentBranch = $branch->getParent();
		$identity = $branch->requireFields(['id']);
		$child = $branch->requireFields(['userId']);
		$parent = $parentBranch->requireFields(['id']);
		LifecycleEvents::$initCalls[$relation->getName()] = (LifecycleEvents::$initCalls[$relation->getName()] ?? 0) + 1;

		return new SingularNode($branch->getParserFields(), $identity, $child, $parent);
	}

	public function load(RelationLoadBranch $branch, LoadRuntime $runtime): void
	{
		$relation = $branch->getRelationRef();
		$queryRelation = $runtime->getQueryRelation($branch);
		$branch->setJoinedAttachment(true);
		LifecycleEvents::$attachmentModes[$relation->getName()] = true;
		$runtime->setQueryContext($branch, $queryRelation->getQuery(), $this->join($queryRelation), $queryRelation);
	}
}

final class LinkedPostsLoader extends LifecycleTestLoader
{
	public function load(RelationLoadBranch $branch, LoadRuntime $runtime): void
	{
		$this->prepareSeparateQuery($branch, $runtime);
	}
}

final class SeparateNestedPostsLoader extends NestedPostsLoader
{
	public function load(RelationLoadBranch $branch, LoadRuntime $runtime): void
	{
		$relation = $branch->getRelationRef();
		$query = new SelectQuery($relation->getCollection(), new NestedAttachmentExecutor());
		$branch->setJoinedAttachment(false);
		$runtime->setQueryContext($branch, $query, $query);
		$runtime->continueWith($branch, 'loadData');
	}
}

final class NestedCommentsLoader extends LifecycleTestLoader
{
	public function load(RelationLoadBranch $branch, LoadRuntime $runtime): void
	{
		$relation = $branch->getRelationRef();
		$query = new SelectQuery($relation->getCollection(), new NestedAttachmentExecutor());
		$branch->setJoinedAttachment(false);
		$runtime->setQueryContext($branch, $query, $query);
		$runtime->continueWith($branch, 'loadData');
	}

	public function loadData(RelationLoadBranch $branch, LoadRuntime $runtime): void
	{
		$runtime->execute($branch, $branch->getQuery());
	}
}

final class BoundaryParentLoader extends LifecycleTestLoader
{
	public function load(RelationLoadBranch $branch, LoadRuntime $runtime): void
	{
		$relation = $branch->getRelationRef();
		LifecycleEvents::$events[] = 'load:' . $relation->getName();
		$query = new SelectQuery($relation->getCollection(), new MultiPassBoundaryExecutor());
		$branch->setJoinedAttachment(false);
		$runtime->setQueryContext($branch, $query, $query);
		$runtime->continueWith($branch, 'loadPivot');
	}

	public function loadPivot(RelationLoadBranch $branch, LoadRuntime $runtime): void
	{
		$relation = $branch->getRelationRef();
		LifecycleEvents::$events[] = 'loadPivot:' . $relation->getName() . ':start';
		$runtime->execute($branch, $branch->getQuery());
		LifecycleEvents::$events[] = 'loadPivot:' . $relation->getName() . ':after-execute';
		$runtime->continueWith($branch, 'loadTargets');
	}

	public function loadTargets(RelationLoadBranch $branch, LoadRuntime $runtime): void
	{
		$relation = $branch->getRelationRef();
		LifecycleEvents::$events[] = 'loadTargets:' . $relation->getName();
	}
}

final class BoundaryChildLoader extends LifecycleTestLoader
{
	public function load(RelationLoadBranch $branch, LoadRuntime $runtime): void
	{
		$relation = $branch->getRelationRef();
		LifecycleEvents::$events[] = 'load:' . $relation->getName();
		$query = new SelectQuery($relation->getCollection(), new MultiPassBoundaryExecutor());
		$branch->setJoinedAttachment(false);
		$runtime->setQueryContext($branch, $query, $query);
		$runtime->continueWith($branch, 'loadData');
	}

	public function loadData(RelationLoadBranch $branch, LoadRuntime $runtime): void
	{
		$relation = $branch->getRelationRef();
		LifecycleEvents::$events[] = 'loadData:' . $relation->getName();
		$runtime->execute($branch, $branch->getQuery());
	}
}

final class TopLevelParentNodeLoader extends LifecycleTestLoader
{
	protected function initNode(RelationLoadBranch $branch, LoadRuntime $runtime): AbstractNode
	{
		LifecycleEvents::$topLevelParentNodeIsRoot = $branch->getParent()->getNode() instanceof RootNode;

		return parent::initNode($branch, $runtime);
	}

	public function load(RelationLoadBranch $branch, LoadRuntime $runtime): void
	{
		$this->prepareSeparateQuery($branch, $runtime);
	}
}
