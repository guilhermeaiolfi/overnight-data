<?php

declare(strict_types=1);

namespace Tests\ON\Data\Query;

use ON\Data\Database\QueryExecutorInterface;
use ON\Data\Definition\Registry;
use ON\Data\Query\Exception\LoadRuntimeException;
use ON\Data\Query\Exception\RelationLoaderException;
use ON\Data\Query\Relation\ColumnIndex;
use ON\Data\Query\Relation\ColumnRole;
use ON\Data\Query\Relation\Loader\AbstractLoader;
use ON\Data\Query\Relation\Loader\HasManyLoader;
use ON\Data\Query\Relation\LoadRuntime;
use ON\Data\Query\Relation\LoadStrategy;
use ON\Data\Query\Relation\RelationLoadBranch;
use ON\Data\Query\Relation\RootLoadBranch;
use ON\Data\Query\Result\Parser\AbstractNode;
use ON\Data\Query\Result\Parser\CollectionNode;
use ON\Data\Query\Result\Parser\RootNode;
use ON\Data\Query\Result\Parser\SingularNode;
use ON\Data\Query\SelectQuery;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\ON\Data\Support\StubQueryRowProjector;

final class LoadRuntimeLifecycleTest extends TestCase
{
	protected function tearDown(): void
	{
		LifecycleEvents::$events = [];
		LifecycleEvents::$referenceSnapshots = [];
		LifecycleEvents::$returnedNodes = [];
		LifecycleEvents::$registerColumns = [];
		LifecycleEvents::$attachmentModes = [];
		LifecycleEvents::$initCalls = [];
		LifecycleEvents::$topLevelParentNodeIsRoot = false;
		LifecycleEvents::$plannedRootColumns = [];
		LifecycleEvents::$branches = [];
	}

	public function testLoadDataRunsAfterParentRowsAreParsed(): void
	{
		$users = new SelectQuery($this->makeBasicRegistry(LifecycleRecordingLoader::class)->getCollection('users'), new LifecycleExecutor());
		$users->posts->separate();

		$users->fetchAll();

		self::assertSame(['load:posts', 'initNode:posts', 'loadData:posts'], LifecycleEvents::$events);
		self::assertSame([['id' => 1]], LifecycleEvents::$referenceSnapshots[0]);
	}

	public function testRegisterRunsOnceAndReturnsTheNodeStoredOnTheBranch(): void
	{
		$users = new SelectQuery($this->makeBasicRegistry(LifecycleRecordingLoader::class)->getCollection('users'), new LifecycleExecutor());
		$users->posts->separate();

		$users->fetchAll();

		self::assertCount(1, LifecycleEvents::$returnedNodes);
		self::assertInstanceOf(CollectionNode::class, LifecycleEvents::$returnedNodes[0]);
		self::assertSame(LifecycleEvents::$returnedNodes[0], LifecycleEvents::$branches['posts']->getNode());
		self::assertSame(['posts' => 1], LifecycleEvents::$initCalls);
	}

	public function testAbstractLoaderRegisterRecursivelyRegistersChildBranchesBeforeParentNodeConstruction(): void
	{
		$users = new SelectQuery($this->makeNestedRegistry()->getCollection('users'), new NestedLifecycleExecutor());
		$users->posts->author->separate();

		$users->fetchAll();

		self::assertSame([
			'initNode:author',
			'initNode:posts',
		], LifecycleEvents::$events);
	}

	public function testDescendantRequiredFieldsArePresentInParentParserNodeColumns(): void
	{
		$users = new SelectQuery($this->makeNestedRegistry()->getCollection('users'), new NestedLifecycleExecutor());
		$users->posts->author->separate();
		$users->fetchAll();

		$columns = array_keys(LifecycleEvents::$registerColumns['posts']);
		sort($columns);

		self::assertSame(['authorId', 'id', 'title', 'userId'], $columns);
	}

	public function testColumnIndexTracksPublicIdentityAndJoinKeyRolesSeparately(): void
	{
		$executor = new LifecycleExecutor();
		$users = new SelectQuery($this->makeBasicRegistry(ExecutingLifecycleLoader::class)->getCollection('users'), $executor);
		$users->posts->select('title');
		$runtime = new LoadRuntime($users, $executor, $users->projection());

		self::assertSame([[
			'id' => 1, 'name' => 'Ada',
			'posts' => [['title' => 'Hello']],
		]], $runtime->fetchAll());

		$branch = LifecycleEvents::$branches['posts'];
		$index = $runtime->getColumnIndex();
		self::assertInstanceOf(ColumnIndex::class, $index);

		$postsRoles = [];
		foreach ($index->getEntries() as $entry) {
			if ($entry->getBranch() === $branch) {
				$postsRoles[$entry->getBagKey()] = $entry->getRole();
			}
		}

		self::assertSame([
			'title' => ColumnRole::Public,
			'id' => ColumnRole::Identity,
			'userId' => ColumnRole::JoinKey,
		], $postsRoles);
		self::assertSame(['title', 'id', 'userId'], array_keys($branch->getRemapColumns()));
	}

	public function testRelationLoadBranchNoLongerKeepsLegacySelectionOrContinuationProperties(): void
	{
		$reflection = new ReflectionClass(RelationLoadBranch::class);

		foreach (['parserFieldMap', 'publicFieldMap', 'parserFields', 'publicFieldOrder', 'selections', 'continuationMethod', 'continuationQuery'] as $property) {
			self::assertFalse($reflection->hasProperty($property), $property);
		}
	}

	public function testRelationLoadBranchNoLongerExposesParserAndPublicFieldProxyMethods(): void
	{
		self::assertFalse(method_exists(RelationLoadBranch::class, 'getParserFields'));
		self::assertFalse(method_exists(RelationLoadBranch::class, 'getPublicFields'));
		self::assertFalse(method_exists(RelationLoadBranch::class, 'getSelections'));
		self::assertFalse(method_exists(RelationLoadBranch::class, 'setContinuation'));
	}

	public function testLoadRuntimeSeparatesPublicOutputFromIdentityRows(): void
	{
		$executor = new LifecycleExecutor();
		$users = new SelectQuery($this->makeBasicRegistry(ExecutingLifecycleLoader::class)->getCollection('users'), $executor);
		$users->select($users->name);
		$users->posts->select('title')->separate();
		$runtime = new LoadRuntime($users, $executor, $users->projection());

		self::assertSame([[
			'name' => 'Ada',
			'posts' => [['title' => 'Hello']],
		]], $runtime->fetchAll());
		self::assertSame([[
			'id' => 1,
			'posts' => [['id' => 10]],
		]], $runtime->getIdentityRows());
	}

	public function testTopLevelRelationBranchesUseTheRootBranchAsParent(): void
	{
		$users = new SelectQuery($this->makeBasicRegistry(LifecycleRecordingLoader::class)->getCollection('users'), new LifecycleExecutor());
		$users->posts->separate();
		$users->fetchAll();
		$branch = LifecycleEvents::$branches['posts'];
		$rootBranch = $branch->getParent();

		self::assertInstanceOf(RootLoadBranch::class, $rootBranch);
		self::assertInstanceOf(RelationLoadBranch::class, $branch);
		self::assertSame([$branch], $rootBranch->getChildren());
	}

	public function testCompositePrimaryKeyDeduplicationStillWorksWhenPublicKeyFieldsAreOmitted(): void
	{
		$employees = new SelectQuery($this->makeCompositeDedupRegistry()->getCollection('employees'), new CompositeDedupExecutor());
		$employees->badges->select('label');
		$employees->select($employees->accountId);

		self::assertSame([
			[
				'accountId' => 10,
				'badges' => [
					['label' => 'Core'],
				],
			],
			[
				'accountId' => 10,
				'badges' => [
					['label' => 'Core'],
				],
			],
			[
				'accountId' => 20,
				'badges' => [['label' => 'Ops']],
			],
		], $employees->fetchAll());
	}

	public function testRootFieldsRequiredDuringPlanningArePresentBeforeRootNodeConstruction(): void
	{
		$users = new SelectQuery($this->makeRootRequirementRegistry()->getCollection('users'), new LifecycleExecutor());
		$users->posts->separate();
		$users->select($users->name);
		$users->fetchAll();
		$rootBranch = LifecycleEvents::$branches['posts']->getParent();

		self::assertArrayHasKey('name', $rootBranch->getRemapColumns());
		self::assertContains('name', LifecycleEvents::$plannedRootColumns);
		self::assertSame([[['name' => 'Ada']]], LifecycleEvents::$referenceSnapshots);
	}

	public function testFetchSuppliesRepresentationSchemaToLoaderBranches(): void
	{
		$users = new SelectQuery($this->makeBasicRegistry(LifecycleRecordingLoader::class)->getCollection('users'), new LifecycleExecutor());
		$users->posts->separate();
		$users->select($users->name);
		$users->fetchAll();
		$schema = $users->getFetchSchema();
		$posts = LifecycleEvents::$branches['posts'];
		$rootBranch = $posts->getParent();

		self::assertNotNull($schema);
		self::assertSame($schema, $rootBranch->getRepresentationSchema());
		self::assertSame(
			$schema->getRelation('posts')->getRelatedSchema(),
			$posts->getRepresentationSchema(),
		);
	}

	public function testExplicitRootFieldRemainsPublicWhilePrimaryKeyStaysInternal(): void
	{
		$users = new SelectQuery($this->makeBasicRegistry(ExecutingLifecycleLoader::class)->getCollection('users'), new ExplicitRootSelectionExecutor());
		$users->posts->separate();
		$users->select($users->name);

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
		$users->posts->separate();
		$users->select($users->name->as('title'));

		self::assertSame([[
			'title' => 'Ada',
			'posts' => [
				['id' => 10, 'userId' => 1, 'title' => 'Hello'],
			],
		]], $users->fetchAll());
	}

	public function testAliasOnlyFetchCompilesSchemaAndAssemblesFromPublicPaths(): void
	{
		$users = new SelectQuery(
			$this->makeBasicRegistry(ExecutingLifecycleLoader::class)->getCollection('users'),
			new AliasOnlyRootExecutor(),
		);
		$users->select($users->name->as('displayName'));

		self::assertTrue($users->needsRowAssemble());
		self::assertSame([['displayName' => 'Ada']], $users->fetchAll());

		$schema = $users->getFetchSchema();
		self::assertNotNull($schema);
		self::assertSame(['displayName'], $schema->getPublicScalarPaths());
		self::assertTrue($schema->hasField('id'));
		self::assertTrue($schema->getField('id')->isImplicit());
	}

	public function testPlainOwnFieldFetchSkipsPlaceSchemaCompile(): void
	{
		$users = new SelectQuery(
			$this->makeBasicRegistry(ExecutingLifecycleLoader::class)->getCollection('users'),
			new LifecycleExecutor(),
		);
		$users->select($users->name);

		self::assertFalse($users->needsRowAssemble());
		$users->fetchAll();
		self::assertNull($users->getFetchSchema());
	}

	public function testAssembleWithoutPlaceSchemaFailsLoudly(): void
	{
		$users = new SelectQuery(
			$this->makeBasicRegistry(ExecutingLifecycleLoader::class)->getCollection('users'),
			new AliasOnlyRootExecutor(),
		);
		$users->select($users->name->as('displayName'));

		$runtime = new LoadRuntime($users, new AliasOnlyRootExecutor(), null);

		$this->expectException(LoadRuntimeException::class);
		$this->expectExceptionMessage('compiled RepresentationSchema');
		$runtime->fetchAll();
	}

	public function testDefaultVisibleRootFieldsRemainPublicWhenNoExplicitRootFieldsAreSelected(): void
	{
		$users = new SelectQuery($this->makeBasicRegistry(ExecutingLifecycleLoader::class)->getCollection('users'), new LifecycleExecutor());
		$users->posts->separate();

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
		$users->posts->separate();
		self::assertSame([[
			'id' => 1,
			'name' => 'Ada',
			'posts' => [],
		]], $users->fetchAll());
		$rootBranch = LifecycleEvents::$branches['posts']->getParent();
		self::assertArrayHasKey('secret', $rootBranch->getRemapColumns());
		self::assertSame(['secret'], LifecycleEvents::$plannedRootColumns);
		self::assertSame([[['secret' => 'shh']]], LifecycleEvents::$referenceSnapshots);
	}

	public function testRootFieldRequirementsReuseExistingOwnFieldColumnInColumnIndex(): void
	{
		$users = new SelectQuery($this->makeRootRequirementRegistry()->getCollection('users'), new AliasedRootSelectionExecutor());
		$users->posts->separate();
		$users->select($users->name->as('title'));
		$rows = $users->fetchAll();
		$rootBranch = LifecycleEvents::$branches['posts']->getParent();
		$remap = $rootBranch->getRemapColumns();

		self::assertSame(['name' => 'c0', 'id' => 'c1'], $remap);
		self::assertSame(['name'], LifecycleEvents::$plannedRootColumns);
		self::assertSame([[['name' => 'Ada']]], LifecycleEvents::$referenceSnapshots);
		self::assertSame([[
			'title' => 'Ada',
			'posts' => [],
		]], $rows);
	}

	public function testJoinedAndLinkedAttachmentsBothPopulatePublicOutput(): void
	{
		$users = new SelectQuery($this->makeMixedAttachmentRegistry()->getCollection('users'), new MixedAttachmentExecutor());
		$users->profile->separate();
		$users->posts->separate();
		$rows = $users->fetchAll();

		self::assertSame([
			'profile' => true,
			'posts' => false,
		], LifecycleEvents::$attachmentModes);
		self::assertSame([50], LifecycleEvents::$referenceSnapshots);
		self::assertSame([[
			'id' => 1,
			'name' => 'Ada',
			'userId' => 1,
			'profile' => ['id' => 50, 'userId' => 1, 'bio' => 'Bio'],
			'posts' => [['id' => 10, 'userId' => 1, 'title' => 'Hello']],
		]], $rows);
	}

	public function testHasManyRejectsJoinStrategyOverrideDuringRuntimePlanning(): void
	{
		$executor = $this->createMock(QueryExecutorInterface::class);
		$executor->expects(self::never())->method('fetchAll');
		$users = new SelectQuery($this->makeBasicRegistry(HasManyLoader::class)->getCollection('users'), $executor);
		$users->posts->join();

		$this->expectException(RelationLoaderException::class);
		$this->expectExceptionMessage('Joined relation loading is not implemented');

		$users->fetchAll();
	}

	public function testNestedCustomLoadersAttachJoinedAndLinkedChildrenWithoutCustomRegisterOverrides(): void
	{
		$users = new SelectQuery($this->makeNestedAttachmentRegistry()->getCollection('users'), new NestedAttachmentExecutor());
		$users->posts->select('id', 'userId', 'authorId', 'title', 'postId');
		$users->posts->author->select('id', 'name')->separate();
		$users->posts->comments->separate();
		self::assertSame([[
			'id' => 1, 'name' => 'Ada',
			'posts' => [[
				'id' => 10, 'userId' => 1, 'authorId' => 7, 'title' => 'Hello', 'postId' => 10,
				'author' => ['id' => 7, 'name' => 'Ana'],
				'comments' => [['id' => 100, 'postId' => 10, 'body' => 'Hi']],
			]],
		]], $users->fetchAll());
		self::assertSame([
			'author' => 1,
			'comments' => 1,
			'posts' => 1,
		], LifecycleEvents::$initCalls);
	}

	public function testSynchronousMultiPassLoadDataCompletesBeforeChildBranchesRun(): void
	{
		$users = new SelectQuery($this->makeMultiPassBoundaryRegistry()->getCollection('users'), new MultiPassBoundaryExecutor());
		$users->posts->comments->separate();

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
		$users->posts->separate();

		$users->fetchAll();

		self::assertTrue(LifecycleEvents::$topLevelParentNodeIsRoot);
	}

	public function testAliasedCompositeKeysDeduplicateRootsAndAttachChildren(): void
	{
		$users = new SelectQuery($this->makeCompositeDedupRegistry()->getCollection('employees'), new CompositeDedupExecutor());
		$users->badges->select('label');
		$users->select($users->tenantId->as('tenant'), $users->name->as('employee'));

		self::assertSame([
			['tenant' => 1, 'employee' => 'Ada', 'badges' => [['label' => 'Core']]],
			['tenant' => 1, 'employee' => 'Grace', 'badges' => [['label' => 'Core']]],
			['tenant' => 2, 'employee' => 'Ada', 'badges' => [['label' => 'Ops']]],
		], $users->fetchAll());
	}

	public function testRootPrimaryKeyColumnsAreTrackedAsIdentityInColumnIndex(): void
	{
		$executor = new LifecycleExecutor();
		$users = new SelectQuery($this->makeBasicRegistry(LifecycleRecordingLoader::class)->getCollection('users'), $executor);
		$users->select($users->name);
		$users->posts->separate();
		$runtime = new LoadRuntime($users, $executor, $users->projection());
		$runtime->fetchAll();

		$rootBranch = LifecycleEvents::$branches['posts']->getParent();
		$idAlias = $rootBranch->getRemapColumns()['id'];
		$entry = $runtime->getColumnIndex()->getEntry($idAlias);

		self::assertNotNull($entry);
		self::assertSame(ColumnRole::Identity, $entry->getRole());
		self::assertSame('id', $entry->getBagKey());
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
		$users->field('userId', 'int');
		$users->primaryKey('id');
		$users->hasOne('profile', 'profiles')->innerKey('id')->outerKey('userId')->loader(JoinedProfileLoader::class)->end();
		$users->hasMany('posts', 'posts')->innerKey('id')->outerKey('userId')->loader(ExecutingLifecycleLoader::class)->end();

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
		$posts->field('postId', 'int');
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
}

final class LifecycleEvents
{
	/** @var array<string, RelationLoadBranch> */
	public static array $branches = [];

	/** @var list<string> */
	public static array $events = [];

	/** @var list<list<array<string, scalar>>> */
	public static array $referenceSnapshots = [];

	/** @var list<AbstractNode> */
	public static array $returnedNodes = [];

	/** @var array<string, array<string, string>> */
	public static array $registerColumns = [];

	/** @var array<string, bool> */
	public static array $attachmentModes = [];

	/** @var array<string, int> */
	public static array $initCalls = [];

	public static bool $topLevelParentNodeIsRoot = false;

	/** @var list<string> */
	public static array $plannedRootColumns = [];
}

final class LifecycleExecutor implements QueryExecutorInterface
{
	public function fetchAll(SelectQuery $query): array
	{
		return match ($query->getCollection()->getName()) {
			'users' => [StubQueryRowProjector::project($query, ['id' => 1, 'name' => 'Ada'])],
			'posts' => [StubQueryRowProjector::project($query, ['id' => 10, 'userId' => 1, 'title' => 'Hello'])],
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
			'users' => [StubQueryRowProjector::project($query, ['name' => 'Ada', 'id' => 1])],
			'posts' => [StubQueryRowProjector::project($query, ['id' => 10, 'userId' => 1, 'title' => 'Hello'])],
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
			'users' => [StubQueryRowProjector::project($query, ['name' => 'Ada', 'id' => 1])],
			'posts' => [StubQueryRowProjector::project($query, ['id' => 10, 'userId' => 1, 'title' => 'Hello'])],
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

final class AliasOnlyRootExecutor implements QueryExecutorInterface
{
	public function fetchAll(SelectQuery $query): array
	{
		return [StubQueryRowProjector::project($query, ['name' => 'Ada', 'id' => 1])];
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
			'users' => [StubQueryRowProjector::project($query, ['id' => 1, 'name' => 'Ada', 'secret' => 'shh'])],
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

final class NestedLifecycleExecutor implements QueryExecutorInterface
{
	public function fetchAll(SelectQuery $query): array
	{
		return match ($query->getCollection()->getName()) {
			'users' => [StubQueryRowProjector::project($query, ['id' => 1, 'name' => 'Ada'])],
			'posts' => [StubQueryRowProjector::project($query, [
				'id' => 10,
				'userId' => 1,
				'authorId' => 7,
				'title' => 'Hello',
				'author.id' => 7,
				'author.name' => 'Ana',
			])],
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
			'users' => [StubQueryRowProjector::project($query, [
				'name' => 'Ada',
				'id' => 1,
				'userId' => 1,
				'profile.id' => 50,
				'profile.userId' => 1,
				'profile.bio' => 'Bio',
			])],
			'posts' => [StubQueryRowProjector::project($query, ['id' => 10, 'userId' => 1, 'title' => 'Hello'])],
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
			'users' => [StubQueryRowProjector::project($query, ['id' => 1, 'name' => 'Ada'])],
			'posts' => [StubQueryRowProjector::project($query, [
				'id' => 10,
				'userId' => 1,
				'authorId' => 7,
				'title' => 'Hello',
				'postId' => 10,
				'author.id' => 7,
				'author.name' => 'Ana',
			])],
			'comments' => [StubQueryRowProjector::project($query, ['id' => 100, 'postId' => 10, 'body' => 'Hi'])],
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
			'users' => [StubQueryRowProjector::project($query, ['id' => 1, 'name' => 'Ada'])],
			'posts' => [StubQueryRowProjector::project($query, ['id' => 10, 'userId' => 1, 'title' => 'Hello'])],
			'comments' => [StubQueryRowProjector::project($query, ['id' => 100, 'postId' => 10, 'body' => 'Hi'])],
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
		$rows = match ($query->getCollection()->getName()) {
			'employees' => [
				['tenantId' => 1, 'accountId' => 10, 'name' => 'Ada'],
				['tenantId' => 1, 'accountId' => 10, 'name' => 'Ada'],
				['tenantId' => 1, 'accountId' => 10, 'name' => 'Grace'],
				['tenantId' => 2, 'accountId' => 20, 'name' => 'Ada'],
			],
			'employee_badges' => [
				['tenantId' => 1, 'employeeName' => 'Ada', 'label' => 'Core'],
				['tenantId' => 1, 'employeeName' => 'Ada', 'label' => 'Core'],
				['tenantId' => 1, 'employeeName' => 'Grace', 'label' => 'Core'],
				['tenantId' => 2, 'employeeName' => 'Ada', 'label' => 'Ops'],
			],
			default => [],
		};

		return StubQueryRowProjector::projectAll($query, $rows);
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
		LifecycleEvents::$branches[$relation->getName()] = $branch;
		$definition = $relation->getDefinition();
		$node = new CollectionNode(
			$branch->getRemapColumns(),
			$relation->getCollection()->getPrimaryKey(),
			$definition->getOuterKeys(),
			$definition->getInnerKeys(),
		);

		LifecycleEvents::$events[] = 'initNode:' . $relation->getName();
		LifecycleEvents::$initCalls[$relation->getName()] = (LifecycleEvents::$initCalls[$relation->getName()] ?? 0) + 1;
		LifecycleEvents::$returnedNodes[] = $node;
		LifecycleEvents::$registerColumns[$relation->getName()] = $branch->getRemapColumns();

		return $node;
	}

	protected function prepareSeparateQuery(RelationLoadBranch $branch, LoadRuntime $runtime): void
	{
		$relation = $branch->getRelationRef();
		$definition = $relation->getDefinition();
		$query = new SelectQuery($relation->getCollection(), new LifecycleExecutor());
		$branch->setJoinedAttachment(false);
		LifecycleEvents::$attachmentModes[$relation->getName()] = false;
		$runtime->setQueryContext($branch, $query, $query);
		$branch->requireFields($relation->getCollection()->getPrimaryKey());
		$branch->requireFields($definition->getOuterKeys());
		$branch->getParent()->requireFields($definition->getInnerKeys());
	}
}

final class LifecycleRecordingLoader extends LifecycleTestLoader
{
	public function load(RelationLoadBranch $branch, LoadRuntime $runtime): void
	{
		$relation = $branch->getRelationRef();
		LifecycleEvents::$events[] = 'load:' . $relation->getName();
		$this->prepareSeparateQuery($branch, $runtime);
		$runtime->continueWith($branch, $this->loadData(...));
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
		$runtime->continueWith($branch, $this->loadData(...));
	}

	public function loadData(RelationLoadBranch $branch, LoadRuntime $runtime): void
	{
		$runtime->execute($branch, $branch->getQuery());
	}
}

class NestedPostsLoader extends AbstractLoader
{
	protected function initNode(RelationLoadBranch $branch, LoadRuntime $runtime): AbstractNode
	{
		$relation = $branch->getRelationRef();
		LifecycleEvents::$events[] = 'initNode:' . $relation->getName();
		LifecycleEvents::$initCalls[$relation->getName()] = (LifecycleEvents::$initCalls[$relation->getName()] ?? 0) + 1;
		LifecycleEvents::$registerColumns['posts'] = $branch->getRemapColumns();

		return new CollectionNode($branch->getRemapColumns(), ['id'], ['userId'], ['id']);
	}

	public function load(RelationLoadBranch $branch, LoadRuntime $runtime): void
	{
		$relation = $branch->getRelationRef();
		$query = new SelectQuery($relation->getCollection(), new NestedLifecycleExecutor());
		$branch->setJoinedAttachment(false);
		$runtime->setQueryContext($branch, $query, $query);
		$branch->requireFields(['id', 'userId']);
		$branch->getParent()->requireFields(['id']);
		$runtime->continueWith($branch, $this->loadData(...));
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
		LifecycleEvents::$events[] = 'initNode:' . $relation->getName();
		LifecycleEvents::$initCalls[$relation->getName()] = (LifecycleEvents::$initCalls[$relation->getName()] ?? 0) + 1;
		LifecycleEvents::$registerColumns['author'] = $branch->getRemapColumns();

		return new SingularNode($branch->getRemapColumns(), ['id'], ['id'], ['authorId']);
	}

	public function load(RelationLoadBranch $branch, LoadRuntime $runtime): void
	{
		$queryRelation = $runtime->getQueryRelation($branch);
		$branch->setJoinedAttachment(true);
		$runtime->setQueryContext($branch, $queryRelation->getQuery(), $this->join($queryRelation), $queryRelation);
		$branch->requireFields(['id']);
		$branch->getParent()->requireFields(['authorId']);
	}
}

final class RootFieldRequirementLoader extends LifecycleTestLoader
{
	public function load(RelationLoadBranch $branch, LoadRuntime $runtime): void
	{
		LifecycleEvents::$plannedRootColumns = $branch->getParent()->requireFields(['name']);
		$this->prepareSeparateQuery($branch, $runtime);
		$runtime->continueWith($branch, $this->loadData(...));
	}

	public function loadData(RelationLoadBranch $branch, LoadRuntime $runtime): void
	{
		LifecycleEvents::$referenceSnapshots[] = $branch->getReferenceValues();
	}
}

final class HiddenRootFieldRequirementLoader extends LifecycleTestLoader
{
	public function load(RelationLoadBranch $branch, LoadRuntime $runtime): void
	{
		LifecycleEvents::$plannedRootColumns = $branch->getParent()->requireFields(['secret']);
		$this->prepareSeparateQuery($branch, $runtime);
		$runtime->continueWith($branch, $this->loadData(...));
	}

	public function loadData(RelationLoadBranch $branch, LoadRuntime $runtime): void
	{
		LifecycleEvents::$referenceSnapshots[] = $branch->getReferenceValues();
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
		LifecycleEvents::$initCalls[$relation->getName()] = (LifecycleEvents::$initCalls[$relation->getName()] ?? 0) + 1;

		return new SingularNode($branch->getRemapColumns(), ['id'], ['userId'], ['id']);
	}

	public function load(RelationLoadBranch $branch, LoadRuntime $runtime): void
	{
		$relation = $branch->getRelationRef();
		$queryRelation = $runtime->getQueryRelation($branch);
		$branch->setJoinedAttachment(true);
		LifecycleEvents::$attachmentModes[$relation->getName()] = true;
		$runtime->setQueryContext($branch, $queryRelation->getQuery(), $this->join($queryRelation), $queryRelation);
		$branch->requireFields(['id', 'userId']);
		$branch->getParent()->requireFields(['id']);
		$runtime->continueWith($branch, static function (RelationLoadBranch $branch): void {
			$parent = $branch->getParent()->getNode();
			if ($parent instanceof RootNode) {
				LifecycleEvents::$referenceSnapshots[] = $parent->getResult()[0]['profile']['id'];
			}
		});
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
		$branch->requireFields(['id', 'userId']);
		$branch->getParent()->requireFields(['id']);
		$runtime->continueWith($branch, $this->loadData(...));
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
		$branch->requireFields($relation->getCollection()->getPrimaryKey());
		$branch->requireFields($relation->getDefinition()->getOuterKeys());
		$branch->getParent()->requireFields($relation->getDefinition()->getInnerKeys());
		$runtime->continueWith($branch, $this->loadData(...));
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
		$branch->requireFields($relation->getCollection()->getPrimaryKey());
		$branch->requireFields($relation->getDefinition()->getOuterKeys());
		$branch->getParent()->requireFields($relation->getDefinition()->getInnerKeys());
		$runtime->continueWith($branch, $this->loadData(...));
	}

	public function loadData(RelationLoadBranch $branch, LoadRuntime $runtime): void
	{
		$relation = $branch->getRelationRef();
		LifecycleEvents::$events[] = 'loadPivot:' . $relation->getName() . ':start';
		$runtime->execute($branch, $branch->getQuery());
		LifecycleEvents::$events[] = 'loadPivot:' . $relation->getName() . ':after-execute';
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
		$branch->requireFields($relation->getCollection()->getPrimaryKey());
		$branch->requireFields($relation->getDefinition()->getOuterKeys());
		$branch->getParent()->requireFields($relation->getDefinition()->getInnerKeys());
		$runtime->continueWith($branch, $this->loadData(...));
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
