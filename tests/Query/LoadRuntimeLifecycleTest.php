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

	public function testNamedNextPassRunsAfterParentRowsAreParsed(): void
	{
		$users = new SelectQuery($this->makeBasicRegistry(LifecycleRecordingLoader::class)->getCollection('users'), new LifecycleExecutor());
		$users->select($users->posts);

		$users->fetchAll();

		self::assertSame(['load:posts', 'initNode:posts', 'loadData:posts'], LifecycleEvents::$events);
		self::assertSame([['id' => 1]], LifecycleEvents::$referenceSnapshots[0]);
	}

	public function testDefaultNextPassRepeatsLoadOnTheSameBranch(): void
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

	public function testNextPassFromRegisterIsRejected(): void
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

		$this->buildPlan($users);

		self::assertSame([
			'initNode:author',
			'initNode:posts',
		], LifecycleEvents::$events);
	}

	public function testDescendantRequiredFieldsArePresentInParentParserNodeColumns(): void
	{
		$users = new SelectQuery($this->makeNestedRegistry()->getCollection('users'), new NestedLifecycleExecutor());
		$users->select($users->posts->author);
		$this->buildPlan($users);

		$columns = LifecycleEvents::$registerColumns['posts'];
		sort($columns);

		self::assertSame(['authorId', 'id', 'userId'], $columns);
	}

	public function testRequestedPublicFieldsRemainSeparateFromInternallyRequiredNodeColumns(): void
	{
		$users = new SelectQuery($this->makeBasicRegistry(LifecycleRecordingLoader::class)->getCollection('users'), new LifecycleExecutor());
		$users->select($users->posts->fields('title'));
		$runtime = $this->buildPlan($users);
		$branches = $this->readProperty($runtime, 'branches');
		$branch = array_values($branches)[0];

		self::assertSame(['title'], $branch->getPublicFields());
		self::assertSame(['title', 'id', 'userId'], $branch->getNodeColumns());
	}

	public function testTopLevelRelationBranchesUseTheRootBranchAsParent(): void
	{
		$users = new SelectQuery($this->makeBasicRegistry(LifecycleRecordingLoader::class)->getCollection('users'), new LifecycleExecutor());
		$users->select($users->posts);
		$runtime = $this->buildPlan($users);
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
		$runtime = $this->buildPlan($users);
		$rootBranch = $this->readProperty($runtime, 'rootBranch');
		$rootNode = $rootBranch->getNode();
		$columns = $this->readProperty($rootNode, 'columns');

		self::assertContains('name', $columns);
		self::assertContains('name', LifecycleEvents::$plannedRootColumns);
	}

	public function testJoinedAndLinkedAttachmentModesAreRecordedDuringLoadAndAppliedDuringRegistration(): void
	{
		$users = new SelectQuery($this->makeMixedAttachmentRegistry()->getCollection('users'), new MixedAttachmentExecutor());
		$users->select($users->profile, $users->posts);
		$runtime = $this->buildPlan($users);
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
		$runtime = $this->buildPlan($users);
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

	public function testExecuteKeepsParentInvocationContextUntilItSchedulesItsNextPass(): void
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

		$this->buildPlan($users);

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

	private function buildPlan(SelectQuery $query): LoadRuntime
	{
		$runtime = new LoadRuntime($query, new LifecycleExecutor());
		$method = new ReflectionMethod(LoadRuntime::class, 'buildPlan');
		$method->setAccessible(true);
		$method->invoke($runtime);

		return $runtime;
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
			$runtime->getNodeColumns(),
			$identity,
			$child,
			$parent,
		);

		LifecycleEvents::$events[] = 'initNode:' . $relation->getName();
		LifecycleEvents::$initCalls[$relation->getName()] = (LifecycleEvents::$initCalls[$relation->getName()] ?? 0) + 1;
		LifecycleEvents::$returnedNodes[] = $node;
		LifecycleEvents::$registerColumns[$relation->getName()] = $runtime->getNodeColumns();

		return $node;
	}

	protected function prepareSeparateQuery(RelationLoadBranch $branch, LoadRuntime $runtime): void
	{
		$relation = $branch->getRelationRef();
		$query = new SelectQuery($relation->getCollection(), new LifecycleExecutor());
		$runtime->setJoinedAttachment(false);
		LifecycleEvents::$attachmentModes[$relation->getName()] = false;
		$runtime->setQueryContext($query, $query);
	}
}

final class LifecycleRecordingLoader extends LifecycleTestLoader
{
	public function load(RelationLoadBranch $branch, LoadRuntime $runtime): void
	{
		$relation = $branch->getRelationRef();
		LifecycleEvents::$events[] = 'load:' . $relation->getName();
		$this->prepareSeparateQuery($branch, $runtime);
		$runtime->nextPass('loadData');
	}

	public function loadData(RelationLoadBranch $branch, LoadRuntime $runtime): void
	{
		$relation = $branch->getRelationRef();
		LifecycleEvents::$events[] = 'loadData:' . $relation->getName();
		LifecycleEvents::$referenceSnapshots[] = $runtime->getReferenceValues();
	}
}

final class RepeatLoadLifecycleLoader extends LifecycleTestLoader
{
	public function load(RelationLoadBranch $branch, LoadRuntime $runtime): void
	{
		LifecycleEvents::$loadCalls++;

		if (LifecycleEvents::$loadCalls === 1) {
			$this->prepareSeparateQuery($branch, $runtime);
			$runtime->nextPass();
		}
	}
}

final class InvalidScheduledMethodLoader extends LifecycleTestLoader
{
	public function load(RelationLoadBranch $branch, LoadRuntime $runtime): void
	{
		$this->prepareSeparateQuery($branch, $runtime);
		$runtime->nextPass('missingMethod');
	}
}

final class RegisterSchedulingLoader extends LifecycleTestLoader
{
	protected function initNode(RelationLoadBranch $branch, LoadRuntime $runtime): AbstractNode
	{
		$node = parent::initNode($branch, $runtime);
		$runtime->nextPass('loadData');

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
		LifecycleEvents::$registerColumns['posts'] = $runtime->getNodeColumns();

		return new CollectionNode($runtime->getNodeColumns(), $identity, $child, $parent);
	}

	public function load(RelationLoadBranch $branch, LoadRuntime $runtime): void
	{
		$relation = $branch->getRelationRef();
		$query = new SelectQuery($relation->getCollection(), new NestedLifecycleExecutor());
		$runtime->setJoinedAttachment(false);
		$runtime->setQueryContext($query, $query);
		$runtime->nextPass('loadData');
	}

	public function loadData(RelationLoadBranch $branch, LoadRuntime $runtime): void
	{
		$runtime->execute($runtime->getQuery());
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
		LifecycleEvents::$registerColumns['author'] = $runtime->getNodeColumns();

		return new SingularNode($runtime->getNodeColumns(), $identity, $child, $parent);
	}

	public function load(RelationLoadBranch $branch, LoadRuntime $runtime): void
	{
		$queryRelation = $runtime->getQueryRelation();
		$runtime->setJoinedAttachment(true);
		$runtime->setQueryContext($queryRelation->getQuery(), $this->join($queryRelation), $queryRelation);
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

		return new SingularNode($runtime->getNodeColumns(), $identity, $child, $parent);
	}

	public function load(RelationLoadBranch $branch, LoadRuntime $runtime): void
	{
		$relation = $branch->getRelationRef();
		$queryRelation = $runtime->getQueryRelation();
		$runtime->setJoinedAttachment(true);
		LifecycleEvents::$attachmentModes[$relation->getName()] = true;
		$runtime->setQueryContext($queryRelation->getQuery(), $this->join($queryRelation), $queryRelation);
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
		$runtime->setJoinedAttachment(false);
		$runtime->setQueryContext($query, $query);
		$runtime->nextPass('loadData');
	}
}

final class NestedCommentsLoader extends LifecycleTestLoader
{
	public function load(RelationLoadBranch $branch, LoadRuntime $runtime): void
	{
		$relation = $branch->getRelationRef();
		$query = new SelectQuery($relation->getCollection(), new NestedAttachmentExecutor());
		$runtime->setJoinedAttachment(false);
		$runtime->setQueryContext($query, $query);
		$runtime->nextPass('loadData');
	}

	public function loadData(RelationLoadBranch $branch, LoadRuntime $runtime): void
	{
		$runtime->execute($runtime->getQuery());
	}
}

final class BoundaryParentLoader extends LifecycleTestLoader
{
	public function load(RelationLoadBranch $branch, LoadRuntime $runtime): void
	{
		$relation = $branch->getRelationRef();
		LifecycleEvents::$events[] = 'load:' . $relation->getName();
		$query = new SelectQuery($relation->getCollection(), new MultiPassBoundaryExecutor());
		$runtime->setJoinedAttachment(false);
		$runtime->setQueryContext($query, $query);
		$runtime->nextPass('loadPivot');
	}

	public function loadPivot(RelationLoadBranch $branch, LoadRuntime $runtime): void
	{
		$relation = $branch->getRelationRef();
		LifecycleEvents::$events[] = 'loadPivot:' . $relation->getName() . ':start';
		$runtime->execute($runtime->getQuery());
		LifecycleEvents::$events[] = 'loadPivot:' . $relation->getName() . ':after-execute';
		$runtime->nextPass('loadTargets');
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
		$runtime->setJoinedAttachment(false);
		$runtime->setQueryContext($query, $query);
		$runtime->nextPass('loadData');
	}

	public function loadData(RelationLoadBranch $branch, LoadRuntime $runtime): void
	{
		$relation = $branch->getRelationRef();
		LifecycleEvents::$events[] = 'loadData:' . $relation->getName();
		$runtime->execute($runtime->getQuery());
	}
}

final class TopLevelParentNodeLoader extends LifecycleTestLoader
{
	protected function initNode(RelationLoadBranch $branch, LoadRuntime $runtime): AbstractNode
	{
		LifecycleEvents::$topLevelParentNodeIsRoot = $runtime->getParentNode() instanceof RootNode;

		return parent::initNode($branch, $runtime);
	}

	public function load(RelationLoadBranch $branch, LoadRuntime $runtime): void
	{
		$this->prepareSeparateQuery($branch, $runtime);
	}
}
