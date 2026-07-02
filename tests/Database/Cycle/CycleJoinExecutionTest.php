<?php

declare(strict_types=1);

namespace Tests\ON\Data\Database\Cycle;

use Closure;
use Cycle\Database\Query\QueryParameters;
use ON\Data\Database\ConnectionConfig;
use ON\Data\Database\Database;
use ON\Data\Database\Exception\UnsupportedQueryException;
use ON\Data\Database\QueryExecutorInterface;
use ON\Data\Definition\Registry;
use ON\Data\Definition\Relation\FirstOfManyRelation;
use ON\Data\Definition\Relation\M2MRelation;
use ON\Data\Query\DerivedQuerySource;
use ON\Data\Query\Exception\LoadRuntimeException;
use ON\Data\Query\Exception\RelationLoaderException;
use ON\Data\Query\Exception\RelationSelectionException;
use ON\Data\Query\Expression\SubqueryExpression;
use ON\Data\Query\Join;
use ON\Data\Query\JoinType;
use ON\Data\Query\Relation\Loader\FirstOfManyLoader;
use ON\Data\Query\Relation\LoadRuntime;
use ON\Data\Query\Relation\LoadStrategy;
use ON\Data\Query\Relation\RelationLoadBranch;
use ON\Data\Query\Relation\RelationSelection;
use ON\Data\Query\Relation\RootLoadBranch;
use ON\Data\Query\SelectQuery;
use function ON\Data\Query\x;
use PDO;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[RequiresPhpExtension('pdo_sqlite')]
final class CycleJoinExecutionTest extends TestCase
{
	private string $databasePath;

	private string $dsn;

	private ?Registry $registry = null;

	private ?Database $database = null;

	protected function setUp(): void
	{
		$this->databasePath = tempnam(sys_get_temp_dir(), 'ondata-joins-');
		$this->dsn = 'sqlite:' . str_replace('\\', '/', $this->databasePath);
		$this->registry = $this->makeRegistry();
		$this->seedDatabase();
		$this->database = Database::connect(ConnectionConfig::dsn('sqlite', $this->dsn));
	}

	protected function tearDown(): void
	{
		$this->database = null;
		$this->registry = null;
		gc_collect_cycles();

		if (is_file($this->databasePath)) {
			@unlink($this->databasePath);
		}
	}

	public function testExplicitJoinSupportsSelectionsConditionsAndOrdering(): void
	{
		$users = $this->database->query($this->registry->getCollection('users'));
		$company = $users->join(
			$this->registry->getCollection('companies'),
			JoinType::LEFT,
			'company',
		);
		$company->on(x()->eq($users->companyId, $company->id));

		$rows = $users
			->select($users->name, $company->name)
			->where(x()->eq($company->active, true))
			->orderBy($company->name->asc(), $users->name->asc())
			->fetchAll();

		self::assertSame([
			['name' => 'Ada', 'company.name' => 'Acme'],
			['name' => 'Grace', 'company.name' => 'Acme'],
		], $rows);
	}

	public function testNullableBelongsToUsesAutomaticLeftJoin(): void
	{
		$users = $this->database->query($this->registry->getCollection('users'));

		$rows = $users
			->select($users->id, $users->company->name)
			->orderBy($users->id->asc())
			->fetchAll();

		self::assertSame([
			['id' => 1, 'company.name' => 'Acme'],
			['id' => 2, 'company.name' => 'Acme'],
			['id' => 3, 'company.name' => null],
		], $rows);
	}

	public function testHasOneJoinExecutes(): void
	{
		$users = $this->database->query($this->registry->getCollection('users'));

		$rows = $users
			->select($users->id, $users->profile->bio)
			->orderBy($users->id->asc())
			->fetchAll();

		self::assertSame([
			['id' => 1, 'profile.bio' => 'Mathematician'],
			['id' => 2, 'profile.bio' => 'Compiler pioneer'],
		], $rows);
	}

	public function testHasManyJoinMultipliesRowsWithoutDeduplication(): void
	{
		$users = $this->database->query($this->registry->getCollection('users'));

		$rows = $users
			->select($users->id, $users->posts->title)
			->orderBy($users->id->asc(), $users->posts->title->asc())
			->fetchAll();

		self::assertSame([
			['id' => 1, 'posts.title' => 'Hello'],
			['id' => 1, 'posts.title' => 'World'],
			['id' => 2, 'posts.title' => 'Graph'],
		], $rows);
	}

	public function testManyToManyCreatesFlatMultipliedRows(): void
	{
		$articles = $this->database->query($this->registry->getCollection('articles'));

		$rows = $articles
			->select($articles->id, $articles->tags->name)
			->orderBy($articles->id->asc(), $articles->tags->name->asc())
			->fetchAll();

		self::assertSame([
			['id' => 1, 'tags.name' => 'orm'],
			['id' => 1, 'tags.name' => 'php'],
			['id' => 2, 'tags.name' => 'sqlite'],
		], $rows);
	}

	public function testCompositeDirectRelationJoinExecutes(): void
	{
		$employees = $this->database->query($this->registry->getCollection('employees'));

		$rows = $employees
			->select($employees->name, $employees->account->name)
			->orderBy($employees->name->asc())
			->fetchAll();

		self::assertSame([
			['name' => 'Ada', 'account.name' => 'Platform'],
			['name' => 'Grace', 'account.name' => 'Platform'],
			['name' => 'Linus', 'account.name' => 'Infra'],
		], $rows);
	}

	public function testCompositeNullableManyToManyUsesLeftJoins(): void
	{
		$articles = $this->database->query($this->registry->getCollection('composite_articles'));

		$rows = $articles
			->select($articles->slug, $articles->tags->name)
			->orderBy($articles->slug->asc(), $articles->tags->name->asc())
			->fetchAll();

		self::assertSame([
			['slug' => 'joins', 'tags.name' => 'orm'],
			['slug' => 'joins', 'tags.name' => 'php'],
			['slug' => 'lonely', 'tags.name' => null],
		], $rows);
	}

	public function testOneRelationCanBeReusedAcrossSelectionConditionAndSorting(): void
	{
		$users = $this->database->query($this->registry->getCollection('users'));

		$rows = $users
			->select($users->posts->title)
			->where(x()->eq($users->posts->published, true))
			->orderBy($users->posts->title->asc())
			->fetchAll();

		self::assertCount(1, $users->getJoins());
		self::assertSame([
			['posts.title' => 'Graph'],
			['posts.title' => 'Hello'],
		], $rows);
	}

	public function testConditionOnlyRelationJoinExecutes(): void
	{
		$users = $this->database->query($this->registry->getCollection('users'));

		$rows = $users
			->select($users->id)
			->where(x()->eq($users->company->active, true))
			->orderBy($users->id->asc())
			->fetchAll();

		self::assertSame([
			['id' => 1],
			['id' => 2],
		], $rows);
	}

	public function testSameTargetCollectionThroughDifferentRelationsUsesDistinctJoins(): void
	{
		$orders = $this->database->query($this->registry->getCollection('orders'));

		$rows = $orders
			->select($orders->billingAddress->city, $orders->shippingAddress->city)
			->orderBy($orders->id->asc())
			->fetchAll();

		self::assertCount(2, $orders->getJoins());
		self::assertSame([
			['billingAddress.city' => 'Sao Paulo', 'shippingAddress.city' => 'Rio'],
			['billingAddress.city' => 'Rio', 'shippingAddress.city' => 'Sao Paulo'],
		], $rows);
	}

	public function testRelationFieldsWorkInAggregateValueOperationGroupingAndHaving(): void
	{
		$posts = $this->database->query($this->registry->getCollection('posts'));

		$rows = $posts
			->select(
				$posts->author->name->upper()->as('author_name'),
				$posts->id->count()->as('post_count'),
			)
			->groupBy($posts->author->name->upper())
			->having(x()->gt($posts->id->count(), 0))
			->orderBy($posts->author->name->upper()->asc())
			->fetchAll();

		self::assertSame([
			['author_name' => 'ADA', 'post_count' => 2],
			['author_name' => 'GRACE', 'post_count' => 1],
		], $rows);
	}

	public function testRelationJoinWorksInsideNestedQueries(): void
	{
		$users = $this->database->query($this->registry->getCollection('users'));
		$posts = $this->registry->getCollection('posts');

		$rows = $users
			->select(
				$users->name,
				(new SubqueryExpression(\ON\Data\Query\query($posts, function (SelectQuery $query) use ($users): void {
					$query
						->select($query->author->name->upper())
						->where(x()->eq($query->userId, $users->id))
						->orderBy($query->id->asc())
						->limit(1);
				})))->as('first_author'),
			)
			->orderBy($users->id->asc())
			->fetchAll();

		self::assertSame([
			['name' => 'Ada', 'first_author' => 'ADA'],
			['name' => 'Grace', 'first_author' => 'GRACE'],
			['name' => 'Linus', 'first_author' => null],
		], $rows);
	}

	public function testItLoadsStructuredBelongsToRelations(): void
	{
		$posts = $this->database->query($this->registry->getCollection('posts'));
		$posts->author->separate();

		$rows = $posts
			->select($posts->id, $posts->title)
			->orderBy($posts->id->asc())
			->fetchAll();

		self::assertSame([
			['id' => 1, 'title' => 'Hello', 'author' => ['id' => 1, 'name' => 'Ada', 'companyId' => 1]],
			['id' => 2, 'title' => 'World', 'author' => ['id' => 1, 'name' => 'Ada', 'companyId' => 1]],
			['id' => 3, 'title' => 'Graph', 'author' => ['id' => 2, 'name' => 'Grace', 'companyId' => 1]],
		], $rows);
	}

	public function testFieldsRestrictBelongsToProjectionWithoutExposingJoinKeys(): void
	{
		$posts = $this->database->query($this->registry->getCollection('posts'));
		$posts->author->fields('name');

		$rows = $posts
			->select($posts->id)
			->orderBy($posts->id->asc())
			->fetchAll();

		self::assertSame([
			['id' => 1, 'author' => ['name' => 'Ada']],
			['id' => 2, 'author' => ['name' => 'Ada']],
			['id' => 3, 'author' => ['name' => 'Grace']],
		], $rows);
	}

	public function testItLoadsStructuredHasManyRelations(): void
	{
		$users = $this->database->query($this->registry->getCollection('users'));
		$users->posts->separate();

		$rows = $users
			->select($users->name)
			->orderBy($users->id->asc())
			->fetchAll();

		self::assertSame([
			[
				'name' => 'Ada',
				'posts' => [
					['id' => 1, 'userId' => 1, 'title' => 'Hello', 'published' => true],
					['id' => 2, 'userId' => 1, 'title' => 'World', 'published' => false],
				],
			],
			[
				'name' => 'Grace',
				'posts' => [
					['id' => 3, 'userId' => 2, 'title' => 'Graph', 'published' => true],
				],
			],
			[
				'name' => 'Linus',
				'posts' => [],
			],
		], $rows);
	}

	public function testDirectConfigAppliesSeparateRelationWhereAndOrderBy(): void
	{
		$users = $this->database->query($this->registry->getCollection('users'));
		$users->posts
			->fields('title')
			->where(x()->eq($users->posts->published, true))
			->orderBy($users->posts->title->desc());

		$rows = $users
			->select($users->name)
			->orderBy($users->id->asc())
			->fetchAll();

		self::assertSame([
			['name' => 'Ada', 'posts' => [['title' => 'Hello']]],
			['name' => 'Grace', 'posts' => [['title' => 'Graph']]],
			['name' => 'Linus', 'posts' => []],
		], $rows);
	}

	public function testSeparateBelongsToRelationWhereDoesNotFilterRootRows(): void
	{
		$posts = $this->database->query($this->registry->getCollection('posts'));
		$posts->author
			->separate()
			->fields('name')
			->where(x()->eq($posts->author->name, 'Ada'));

		$rows = $posts
			->select($posts->title)
			->orderBy($posts->id->asc())
			->fetchAll();

		self::assertSame([
			['title' => 'Hello', 'author' => ['name' => 'Ada']],
			['title' => 'World', 'author' => ['name' => 'Ada']],
			['title' => 'Graph', 'author' => null],
		], $rows);
	}

	public function testFieldsLoadTheRelationAndRestrictPublicFields(): void
	{
		$users = $this->database->query($this->registry->getCollection('users'));
		$users->posts->fields('title');

		$rows = $users
			->select($users->name)
			->orderBy($users->id->asc())
			->fetchAll();

		self::assertSame([
			[
				'name' => 'Ada',
				'posts' => [
					['title' => 'Hello'],
					['title' => 'World'],
				],
			],
			[
				'name' => 'Grace',
				'posts' => [
					['title' => 'Graph'],
				],
			],
			[
				'name' => 'Linus',
				'posts' => [],
			],
		], $rows);
	}

	public function testJoinedRelationWhereAndOrderByAreRejectedForStructuredLoading(): void
	{
		$users = $this->database->query($this->registry->getCollection('users'));

		try {
			$users->posts->join()->where(x()->eq($users->posts->published, true));
			$users->fetchAll();
			self::fail('Expected joined relation where rejection.');
		} catch (RelationLoaderException $exception) {
			self::assertStringContainsString('conditions that are not supported', $exception->getMessage());
		}

		$users = $this->database->query($this->registry->getCollection('users'));

		$this->expectException(RelationLoaderException::class);
		$this->expectExceptionMessage('ordering that is not supported');
		$users->posts->join()->orderBy($users->posts->title->asc());
		$users->fetchAll();
	}

	public function testRepeatedStructuredLoadStrategiesUseLatestCall(): void
	{
		$users = $this->database->query($this->registry->getCollection('users'));

		$users->posts->strategy(LoadStrategy::JOIN)->strategy(LoadStrategy::SEPARATE_QUERY);

		self::assertSame(LoadStrategy::SEPARATE_QUERY, $users->getRelationSelections()->getAll()[0]->getStrategy());
	}

	public function testItProjectsM2MTargetsWithoutExposingThroughRows(): void
	{
		$users = $this->database->query($this->registry->getCollection('users'));
		$users->roles->separate();

		$rows = $users
			->select($users->name)
			->orderBy($users->id->asc())
			->fetchAll();

		self::assertSame([
			[
				'name' => 'Ada',
				'roles' => [
					['id' => 10, 'name' => 'Admin'],
					['id' => 11, 'name' => 'Editor'],
				],
			],
			[
				'name' => 'Grace',
				'roles' => [
					['id' => 10, 'name' => 'Admin'],
				],
			],
			[
				'name' => 'Linus',
				'roles' => [],
			],
		], $rows);
	}

	public function testM2MStructuredLoadingUsesThroughRowsToAttachSharedTargets(): void
	{
		$users = $this->database->query($this->registry->getCollection('users'));
		$users->roles->fields('name');

		$rows = $users
			->select($users->name)
			->orderBy($users->id->asc())
			->fetchAll();

		self::assertSame([
			['name' => 'Ada', 'roles' => [['name' => 'Admin'], ['name' => 'Editor']]],
			['name' => 'Grace', 'roles' => [['name' => 'Admin']]],
			['name' => 'Linus', 'roles' => []],
		], $rows);
	}

	public function testItLoadsNestedRelationsBelowM2MTargetBranches(): void
	{
		$users = $this->database->query($this->registry->getCollection('users'));
		$users->roles->permissions->separate();

		$rows = $users
			->select($users->name)
			->orderBy($users->id->asc())
			->fetchAll();

		self::assertSame([
			[
				'name' => 'Ada',
				'roles' => [
					[
						'permissions' => [
							['id' => 100, 'roleId' => 10, 'name' => 'manage_users'],
							['id' => 101, 'roleId' => 10, 'name' => 'deploy'],
						],
					],
					[
						'permissions' => [
							['id' => 102, 'roleId' => 11, 'name' => 'edit_content'],
						],
					],
				],
			],
			[
				'name' => 'Grace',
				'roles' => [
					[
						'permissions' => [
							['id' => 100, 'roleId' => 10, 'name' => 'manage_users'],
							['id' => 101, 'roleId' => 10, 'name' => 'deploy'],
						],
					],
				],
			],
			[
				'name' => 'Linus',
				'roles' => [],
			],
		], $rows);
	}

	public function testM2MStructuredLoadingSupportsCompositeParentAndThroughKeys(): void
	{
		$articles = $this->database->query($this->registry->getCollection('composite_articles'));
		$articles->tags->separate();

		$rows = $articles
			->select($articles->slug)
			->orderBy($articles->slug->asc())
			->fetchAll();

		self::assertSame([
			[
				'slug' => 'joins',
				'tags' => [
					['tenantId' => 1, 'slug' => 'php', 'name' => 'php'],
					['tenantId' => 1, 'slug' => 'orm', 'name' => 'orm'],
				],
			],
			[
				'slug' => 'lonely',
				'tags' => [],
			],
		], $rows);
	}

	public function testFieldsRejectExplicitEmptyLists(): void
	{
		$users = $this->database->query($this->registry->getCollection('users'));

		$this->expectException(RelationSelectionException::class);
		$users->posts->fields([]);
	}

	public function testItKeepsIntermediateRelationsVisibleButStructuralByDefault(): void
	{
		$users = $this->database->query($this->registry->getCollection('users'));
		$users->posts->author->separate();

		$rows = $users
			->select($users->name)
			->orderBy($users->id->asc())
			->fetchAll();

		self::assertSame([
			[
				'name' => 'Ada',
				'posts' => [
					['author' => ['id' => 1, 'name' => 'Ada', 'companyId' => 1]],
					['author' => ['id' => 1, 'name' => 'Ada', 'companyId' => 1]],
				],
			],
			[
				'name' => 'Grace',
				'posts' => [
					['author' => ['id' => 2, 'name' => 'Grace', 'companyId' => 1]],
				],
			],
			[
				'name' => 'Linus',
				'posts' => [],
			],
		], $rows);
	}

	public function testNestedRelationBranchesCanUseIndependentFieldLists(): void
	{
		$users = $this->database->query($this->registry->getCollection('users'));
		$users->posts->fields('title')->author->fields('name');

		$rows = $users
			->select($users->name)
			->orderBy($users->id->asc())
			->fetchAll();

		self::assertSame([
			[
				'name' => 'Ada',
				'posts' => [
					['title' => 'Hello', 'author' => ['name' => 'Ada']],
					['title' => 'World', 'author' => ['name' => 'Ada']],
				],
			],
			[
				'name' => 'Grace',
				'posts' => [
					['title' => 'Graph', 'author' => ['name' => 'Grace']],
				],
			],
			[
				'name' => 'Linus',
				'posts' => [],
			],
		], $rows);
	}

	public function testItMergesRepeatedRelationSelections(): void
	{
		$users = $this->database->query($this->registry->getCollection('users'));
		$users->posts->fields('id');
		$users->posts->fields('title');

		$rows = $users
			->select($users->name)
			->orderBy($users->id->asc())
			->fetchAll();

		self::assertSame([
			[
				'name' => 'Ada',
				'posts' => [
					['title' => 'Hello'],
					['title' => 'World'],
				],
			],
			[
				'name' => 'Grace',
				'posts' => [
					['title' => 'Graph'],
				],
			],
			[
				'name' => 'Linus',
				'posts' => [],
			],
		], $rows);
	}

	public function testStrategyConfigurationKeepsStructuredFieldSelection(): void
	{
		$users = $this->database->query($this->registry->getCollection('users'));
		$users->posts->fields('title')->separate();

		$rows = $users
			->select($users->name)
			->orderBy($users->id->asc())
			->fetchAll();

		self::assertSame([
			[
				'name' => 'Ada',
				'posts' => [
					['title' => 'Hello'],
					['title' => 'World'],
				],
			],
			[
				'name' => 'Grace',
				'posts' => [
					['title' => 'Graph'],
				],
			],
			[
				'name' => 'Linus',
				'posts' => [],
			],
		], $rows);
	}

	public function testExplicitIntermediateLoadKeepsParentBranchVisible(): void
	{
		$users = $this->database->query($this->registry->getCollection('users'));
		$users->posts->separate()->author->separate();

		$rows = $users
			->select($users->name)
			->orderBy($users->id->asc())
			->fetchAll();

		self::assertSame([
			[
				'name' => 'Ada',
				'posts' => [
					['id' => 1, 'userId' => 1, 'title' => 'Hello', 'published' => true, 'author' => ['id' => 1, 'name' => 'Ada', 'companyId' => 1]],
					['id' => 2, 'userId' => 1, 'title' => 'World', 'published' => false, 'author' => ['id' => 1, 'name' => 'Ada', 'companyId' => 1]],
				],
			],
			[
				'name' => 'Grace',
				'posts' => [
					['id' => 3, 'userId' => 2, 'title' => 'Graph', 'published' => true, 'author' => ['id' => 2, 'name' => 'Grace', 'companyId' => 1]],
				],
			],
			[
				'name' => 'Linus',
				'posts' => [],
			],
		], $rows);
	}

	public function testHiddenIntermediateBranchesPromoteVisibleDescendants(): void
	{
		$users = $this->database->query($this->registry->getCollection('users'));
		$users->posts->hidden()->author->separate();

		$rows = $users
			->select($users->name)
			->orderBy($users->id->asc())
			->fetchAll();

		self::assertSame([
			[
				'name' => 'Ada',
				'author' => [
					['id' => 1, 'name' => 'Ada', 'companyId' => 1],
				],
			],
			[
				'name' => 'Grace',
				'author' => [
					['id' => 2, 'name' => 'Grace', 'companyId' => 1],
				],
			],
			[
				'name' => 'Linus',
				'author' => [],
			],
		], $rows);
	}

	public function testMergedSelectionsPromoteHiddenBranchesWithoutDuplicatingOutput(): void
	{
		$users = $this->database->query($this->registry->getCollection('users'));
		$users->posts->hidden()->author->separate();
		$users->posts->visible()->separate();

		$rows = $users
			->select($users->name)
			->orderBy($users->id->asc())
			->fetchAll();

		self::assertSame([
			[
				'name' => 'Ada',
				'posts' => [
					['id' => 1, 'userId' => 1, 'title' => 'Hello', 'published' => true, 'author' => ['id' => 1, 'name' => 'Ada', 'companyId' => 1]],
					['id' => 2, 'userId' => 1, 'title' => 'World', 'published' => false, 'author' => ['id' => 1, 'name' => 'Ada', 'companyId' => 1]],
				],
			],
			[
				'name' => 'Grace',
				'posts' => [
					['id' => 3, 'userId' => 2, 'title' => 'Graph', 'published' => true, 'author' => ['id' => 2, 'name' => 'Grace', 'companyId' => 1]],
				],
			],
			[
				'name' => 'Linus',
				'posts' => [],
			],
		], $rows);
	}

	public function testHiddenIntermediatePluralBranchesPreserveDistinctPromotedRecords(): void
	{
		$users = $this->database->query($this->registry->getCollection('users'));
		$users->posts->hidden()->comments->author->separate();

		$rows = $users
			->select($users->name)
			->orderBy($users->id->asc())
			->fetchAll();

		self::assertSame([
			[
				'name' => 'Ada',
				'comments' => [
					['author' => ['id' => 1, 'name' => 'Ada', 'companyId' => 1]],
					['author' => ['id' => 2, 'name' => 'Grace', 'companyId' => 1]],
				],
			],
			[
				'name' => 'Grace',
				'comments' => [
					['author' => ['id' => 1, 'name' => 'Ada', 'companyId' => 1]],
				],
			],
			[
				'name' => 'Linus',
				'comments' => [],
			],
		], $rows);
	}

	public function testFirstOfManySeparateLoadingReturnsSingleOrderedChildPerParent(): void
	{
		$users = $this->database->query($this->registry->getCollection('users'));
		$users->latestPost->fields('id', 'title');

		$rows = $users
			->select($users->name)
			->orderBy($users->id->asc())
			->fetchAll();

		self::assertSame([
			['name' => 'Ada', 'latestPost' => ['id' => 11, 'title' => 'Alpha']],
			['name' => 'Grace', 'latestPost' => ['id' => 20, 'title' => 'Beta']],
			['name' => 'Linus', 'latestPost' => null],
		], $rows);
	}

	public function testFirstOfManySeparateLoadingUsesWindowedDerivedQuery(): void
	{
		$executor = $this->executorFromDatabase($this->database);
		$recording = new RecordingQueryExecutor(
			$executor,
			fn (SelectQuery $query): string => $this->compileSqlWithExecutor($executor, $query),
		);
		$database = new Database($recording);
		$users = $database->query($this->registry->getCollection('users'));
		$users->latestPost->fields('id', 'title', 'createdAt');

		$users
			->select($users->name)
			->orderBy($users->id->asc())
			->fetchAll();

		$sql = $recording->derivedSql()[0] ?? '';

		self::assertStringContainsString('ROW_NUMBER() OVER', $sql);
		self::assertStringContainsString('PARTITION BY', $sql);
		self::assertStringContainsString('ORDER BY', $sql);
		self::assertStringContainsString('"q1"."created_at" AS "createdAt"', $sql);
		self::assertStringContainsString('"__ondata_first_of_many"."createdAt" AS "createdAt"', $sql);
		self::assertStringNotContainsString('"__ondata_first_of_many"."created_at"', $sql);
		self::assertStringContainsString('__ondata_rank', $sql);
		self::assertStringContainsString('WHERE "__ondata_first_of_many"."__ondata_rank" = ?', $sql);
	}

	public function testFirstOfManyLoaderDoesNotGuessDerivedOutputNamesFromExpressionTypes(): void
	{
		$contents = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Query/Relation/Loader/FirstOfManyLoader.php');

		self::assertFalse(method_exists(FirstOfManyLoader::class, 'derivedFieldName'));
		self::assertStringNotContainsString('AliasedExpression', $contents);
		self::assertStringNotContainsString('FieldRef', $contents);
		self::assertStringNotContainsString('SourceFieldExpression', $contents);
	}

	public function testFirstOfManyRequiresExecutorSupportForDerivedWindowedQuery(): void
	{
		$database = new Database(new FirstOfManyFallbackExecutor());
		$users = $database->query($this->registry->getCollection('users'));
		$users->latestPost->fields('id', 'title');

		$this->expectException(UnsupportedQueryException::class);
		$this->expectExceptionMessage('derived-source and window-expression support');

		$users
			->select($users->name)
			->fetchAll();
	}

	public function testFirstOfManyWindowedLoadingKeepsInternalFieldsOutOfPublicOutput(): void
	{
		$users = $this->database->query($this->registry->getCollection('users'));
		$users->latestPost->fields('title');

		$rows = $users
			->select($users->name)
			->orderBy($users->id->asc())
			->fetchAll();

		self::assertSame(['title'], array_keys($rows[0]['latestPost']));
		self::assertSame(['title'], array_keys($rows[1]['latestPost']));
	}

	public function testFirstOfManyNestedLoadingAttachesBelowSingleChild(): void
	{
		$users = $this->database->query($this->registry->getCollection('users'));
		$users->latestPost->fields('title')->author->fields('name');

		$rows = $users
			->select($users->name)
			->orderBy($users->id->asc())
			->fetchAll();

		self::assertSame([
			['name' => 'Ada', 'latestPost' => ['title' => 'Alpha', 'author' => ['name' => 'Grace']]],
			['name' => 'Grace', 'latestPost' => ['title' => 'Beta', 'author' => ['name' => 'Grace']]],
			['name' => 'Linus', 'latestPost' => null],
		], $rows);
	}

	public function testFirstOfManyRequiresDefinitionOrderMetadata(): void
	{
		$users = $this->database->query($this->registry->getCollection('users'));
		$users->latestPostMissingOrder->fields('title');

		$this->expectException(RelationLoaderException::class);
		$this->expectExceptionMessage('requires deterministic orderBy metadata');

		$users
			->select($users->name)
			->fetchAll();
	}

	public function testFirstOfManySupportsSelectionConditionsButRejectsSelectionOrderBy(): void
	{
		$users = $this->database->query($this->registry->getCollection('users'));
		$users->latestPost
			->fields('title')
			->where(x()->eq($users->latestPost->title, 'Zulu'));

		$rows = $users
			->select($users->name)
			->orderBy($users->id->asc())
			->fetchAll();

		self::assertSame([
			['name' => 'Ada', 'latestPost' => ['title' => 'Zulu']],
			['name' => 'Grace', 'latestPost' => null],
			['name' => 'Linus', 'latestPost' => null],
		], $rows);

		$users = $this->database->query($this->registry->getCollection('users'));
		$users->latestPost
			->fields('title')
			->orderBy($users->latestPost->title->desc());

		$this->expectException(RelationLoaderException::class);
		$this->expectExceptionMessage('FirstOfMany ordering comes from deterministic definition-level orderBy metadata and selection-level orderBy is unsupported');

		$users
			->select($users->name)
			->fetchAll();
	}

	public function testFirstOfManySupportsCompositeRelationKeysAndCompositePrimaryKeys(): void
	{
		$employees = $this->database->query($this->registry->getCollection('employees'));
		$employees->latestBadge->fields('badgeId', 'label');

		$rows = $employees
			->select($employees->name)
			->orderBy($employees->name->asc())
			->fetchAll();

		self::assertSame([
			['name' => 'Ada', 'latestBadge' => ['badgeId' => 1, 'label' => 'Core']],
			['name' => 'Grace', 'latestBadge' => ['badgeId' => 1, 'label' => 'Compiler']],
			['name' => 'Linus', 'latestBadge' => ['badgeId' => 1, 'label' => 'Kernel']],
		], $rows);
	}

	public function testFirstOfManyJoinLoadingIsUnsupported(): void
	{
		$users = $this->database->query($this->registry->getCollection('users'));

		$this->expectException(UnsupportedQueryException::class);
		$this->expectExceptionMessage('cannot use JOIN loading');

		$users
			->select($users->latestPost->title)
			->fetchAll();
	}

	public function testFirstOfManyLoaderJoinIsUnsupported(): void
	{
		$users = $this->database->query($this->registry->getCollection('users'));

		$this->expectException(RelationLoaderException::class);
		$this->expectExceptionMessage('cannot use JOIN loading');

		$users->latestPost->getLoader()->join($users->latestPost);
	}

	public function testFirstOfManyStructuredJoinStrategyIsUnsupported(): void
	{
		$users = $this->database->query($this->registry->getCollection('users'));
		$users->latestPost->join();

		$this->expectException(RelationLoaderException::class);
		$this->expectExceptionMessage('cannot use JOIN loading');

		$users
			->select($users->name)
			->fetchAll();
	}

	public function testRelationWhereAndOrderByAreRejected(): void
	{
		$users = $this->database->query($this->registry->getCollection('users_with_scoped_posts'));

		try {
			$users->select($users->posts->title)->fetchAll();
			self::fail('Expected relation where rejection.');
		} catch (UnsupportedQueryException $exception) {
			self::assertStringContainsString('conditions that are not supported', $exception->getMessage());
		}

		$users = $this->database->query($this->registry->getCollection('users_with_ordered_posts'));

		$this->expectException(UnsupportedQueryException::class);
		$this->expectExceptionMessage('ordering that is not supported');
		$users->select($users->posts->title)->fetchAll();
	}

	public function testThroughWhereIsRejected(): void
	{
		$articles = $this->database->query($this->registry->getCollection('articles_with_scoped_tags'));

		$this->expectException(UnsupportedQueryException::class);
		$this->expectExceptionMessage('through conditions that are not supported');
		$articles->select($articles->tags->name)->fetchAll();
	}

	public function testStructuredManyToManyStillRejectsUnsupportedThroughConstraints(): void
	{
		$articles = $this->database->query($this->registry->getCollection('articles_with_scoped_tags'));
		$articles->tags->separate();

		$this->expectException(RelationLoaderException::class);
		$this->expectExceptionMessage('through conditions that are not supported');
		$articles->select($articles->id)->fetchAll();
	}

	public function testMissingKeysAndMissingThroughMetadataBecomeUnsupportedQueryExceptions(): void
	{
		$users = $this->database->query($this->registry->getCollection('users_missing_keys'));

		try {
			$users->select($users->posts->title)->fetchAll();
			self::fail('Expected missing-key rejection.');
		} catch (UnsupportedQueryException $exception) {
			self::assertStringContainsString('cannot be joined because its key lists are incomplete', $exception->getMessage());
		}

		$articles = $this->database->query($this->registry->getCollection('articles_missing_through'));

		$this->expectException(UnsupportedQueryException::class);
		$this->expectExceptionMessage('is missing through metadata');
		$articles->select($articles->tags->name)->fetchAll();
	}

	public function testFailedDirectRelationResolutionLeavesNoStoredJoinsAndRetryPreservesOriginalError(): void
	{
		$users = $this->database->query($this->registry->getCollection('users_missing_keys'));
		$users->select($users->posts->title);

		for ($attempt = 0; $attempt < 2; $attempt++) {
			try {
				$users->fetchAll();
				self::fail('Expected missing-key rejection.');
			} catch (UnsupportedQueryException $exception) {
				self::assertStringContainsString('cannot be joined because its key lists are incomplete', $exception->getMessage());
			}

			self::assertSame([], $users->getJoins());
		}
	}

	public function testFailedManyToManyResolutionLeavesNoStoredJoinsAndRetryPreservesOriginalError(): void
	{
		$articles = $this->database->query($this->registry->getCollection('articles_missing_through'));
		$articles->select($articles->tags->name);

		for ($attempt = 0; $attempt < 2; $attempt++) {
			try {
				$articles->fetchAll();
				self::fail('Expected missing-through rejection.');
			} catch (UnsupportedQueryException $exception) {
				self::assertStringContainsString('is missing through metadata', $exception->getMessage());
			}

			self::assertSame([], $articles->getJoins());
		}
	}

	public function testExplicitJoinWithRelationSourceIsCanonicalizedBeforeStorage(): void
	{
		$users = $this->database->query($this->registry->getCollection('users'));

		$users->join(
			$this->registry->getCollection('departments'),
			JoinType::LEFT,
			'department',
			$users->company,
		);

		self::assertSame(['company', 'department'], array_map(
			static fn (Join $join): string => $join->getName(),
			$users->getJoins(),
		));
	}

	public function testExplicitJoinRejectsUnresolvedRelationFieldsInsideOnCondition(): void
	{
		$users = $this->database->query($this->registry->getCollection('users'));
		$department = $users->join($this->registry->getCollection('departments'), JoinType::LEFT, 'department');
		$department->on(x()->eq($users->company->id, $department->companyId));

		$this->expectException(UnsupportedQueryException::class);
		$this->expectExceptionMessage('cannot use unresolved relation path "company" inside ON conditions');
		$users->select($users->name)->fetchAll();
	}

	public function testLoaderLoadRequiresRuntimeRegistrationContext(): void
	{
		$users = $this->database->query($this->registry->getCollection('users'));
		$branch = new RelationLoadBranch(
			new RelationSelection($users->posts, false, true, null),
			new RootLoadBranch($users, static fn (string $fieldName): string => $fieldName),
			$users->posts->getLoader(),
			[],
		);

		$this->expectException(LoadRuntimeException::class);
		$users->posts->getLoader()->load(
			$branch,
			new LoadRuntime($users, new class () implements QueryExecutorInterface {
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
			}),
		);
	}

	private function makeRegistry(): Registry
	{
		$registry = new Registry();

		$companies = $registry->collection('companies');
		$companies->table('companies');
		$companies->field('id', 'int');
		$companies->field('name', 'string');
		$companies->field('active', 'bool');
		$companies->primaryKey('id');

		$profiles = $registry->collection('profiles');
		$profiles->table('profiles');
		$profiles->field('id', 'int');
		$profiles->field('userId', 'int')->column('user_id');
		$profiles->field('bio', 'string');
		$profiles->primaryKey('id');

		$users = $registry->collection('users');
		$users->table('users');
		$users->field('id', 'int');
		$users->field('name', 'string');
		$users->field('companyId', 'int')->column('company_id')->nullable(true);
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
			->collection('first_posts')
			->innerKey('id')
			->outerKey('userId')
			->orderBy(['rank' => 'asc']);
		$users->relation('latestPostMissingOrder', FirstOfManyRelation::class)
			->collection('first_posts')
			->innerKey('id')
			->outerKey('userId');
		$users->primaryKey('id');

		$usersWithScopedPosts = $registry->collection('users_with_scoped_posts');
		$usersWithScopedPosts->table('users');
		$usersWithScopedPosts->field('id', 'int');
		$usersWithScopedPosts->field('name', 'string');
		$usersWithScopedPosts->relation('posts')->collection('posts')->innerKey('id')->outerKey('userId')->where(['published' => true]);
		$usersWithScopedPosts->primaryKey('id');

		$usersWithOrderedPosts = $registry->collection('users_with_ordered_posts');
		$usersWithOrderedPosts->table('users');
		$usersWithOrderedPosts->field('id', 'int');
		$usersWithOrderedPosts->field('name', 'string');
		$usersWithOrderedPosts->relation('posts')->collection('posts')->innerKey('id')->outerKey('userId')->orderBy(['title' => 'asc']);
		$usersWithOrderedPosts->primaryKey('id');

		$usersMissingKeys = $registry->collection('users_missing_keys');
		$usersMissingKeys->table('users');
		$usersMissingKeys->field('id', 'int');
		$usersMissingKeys->field('name', 'string');
		$usersMissingKeys->relation('posts')->collection('posts');
		$usersMissingKeys->primaryKey('id');

		$posts = $registry->collection('posts');
		$posts->table('posts');
		$posts->field('id', 'int');
		$posts->field('userId', 'int')->column('user_id');
		$posts->field('title', 'string');
		$posts->field('published', 'bool');
		$posts->belongsTo('author', 'users')->innerKey('userId')->outerKey('id')->end();
		$posts->hasMany('comments', 'comments')->innerKey('id')->outerKey('postId')->end();
		$posts->primaryKey('id');

		$firstPosts = $registry->collection('first_posts');
		$firstPosts->table('first_posts');
		$firstPosts->field('id', 'int');
		$firstPosts->field('userId', 'int')->column('user_id');
		$firstPosts->field('authorId', 'int')->column('author_id');
		$firstPosts->field('title', 'string');
		$firstPosts->field('createdAt', 'string')->column('created_at');
		$firstPosts->field('rank', 'int')->hidden(true);
		$firstPosts->belongsTo('author', 'users')->innerKey('authorId')->outerKey('id')->end();
		$firstPosts->primaryKey('id');

		$comments = $registry->collection('comments');
		$comments->table('comments');
		$comments->field('id', 'int');
		$comments->field('postId', 'int')->column('post_id');
		$comments->field('authorId', 'int')->column('author_id');
		$comments->field('body', 'string');
		$comments->belongsTo('author', 'users')->innerKey('authorId')->outerKey('id')->end();
		$comments->primaryKey('id');

		$roles = $registry->collection('roles');
		$roles->table('roles');
		$roles->field('id', 'int');
		$roles->field('name', 'string');
		$roles->hasMany('permissions', 'permissions')->innerKey('id')->outerKey('roleId')->end();
		$roles->primaryKey('id');

		$userRoles = $registry->collection('user_roles');
		$userRoles->table('user_roles');
		$userRoles->field('user_id', 'int');
		$userRoles->field('role_id', 'int');

		$permissions = $registry->collection('permissions');
		$permissions->table('permissions');
		$permissions->field('id', 'int');
		$permissions->field('roleId', 'int')->column('role_id');
		$permissions->field('name', 'string');
		$permissions->primaryKey('id');

		$addresses = $registry->collection('addresses');
		$addresses->table('addresses');
		$addresses->field('id', 'int');
		$addresses->field('city', 'string');
		$addresses->primaryKey('id');

		$orders = $registry->collection('orders');
		$orders->table('orders');
		$orders->field('id', 'int');
		$orders->field('billingAddressId', 'int')->column('billing_address_id');
		$orders->field('shippingAddressId', 'int')->column('shipping_address_id');
		$orders->belongsTo('billingAddress', 'addresses')->innerKey('billingAddressId')->outerKey('id')->end();
		$orders->belongsTo('shippingAddress', 'addresses')->innerKey('shippingAddressId')->outerKey('id')->end();
		$orders->primaryKey('id');

		$articles = $registry->collection('articles');
		$articles->table('articles');
		$articles->field('id', 'int');
		$articles->field('title', 'string');
		$articles->primaryKey('id');
		$articles->relation('tags', M2MRelation::class)
			->collection('tags')
			->innerKey('id')
			->outerKey('id')
			->through('article_tag')
				->innerKey('article_id')
				->outerKey('tag_id')
				->end();

		$articlesWithScopedTags = $registry->collection('articles_with_scoped_tags');
		$articlesWithScopedTags->table('articles');
		$articlesWithScopedTags->field('id', 'int');
		$articlesWithScopedTags->field('title', 'string');
		$articlesWithScopedTags->relation('tags', M2MRelation::class)
			->collection('tags')
			->innerKey('id')
			->outerKey('id')
			->through('article_tag')
				->innerKey('article_id')
				->outerKey('tag_id')
				->where(['active' => true])
				->end();
		$articlesWithScopedTags->primaryKey('id');

		$articlesMissingThrough = $registry->collection('articles_missing_through');
		$articlesMissingThrough->table('articles');
		$articlesMissingThrough->field('id', 'int');
		$articlesMissingThrough->field('title', 'string');
		$articlesMissingThrough->relation('tags', M2MRelation::class)
			->collection('tags')
			->innerKey('id')
			->outerKey('id');
		$articlesMissingThrough->primaryKey('id');

		$tags = $registry->collection('tags');
		$tags->table('tags');
		$tags->field('id', 'int');
		$tags->field('name', 'string');
		$tags->primaryKey('id');

		$articleTag = $registry->collection('article_tag');
		$articleTag->table('article_tag');
		$articleTag->field('article_id', 'int');
		$articleTag->field('tag_id', 'int');

		$accounts = $registry->collection('accounts');
		$accounts->table('accounts');
		$accounts->field('tenantId', 'int')->column('tenant_id');
		$accounts->field('id', 'int');
		$accounts->field('name', 'string');
		$accounts->primaryKey('tenantId', 'id');

		$employees = $registry->collection('employees');
		$employees->table('employees');
		$employees->field('tenantId', 'int')->column('tenant_id');
		$employees->field('accountId', 'int')->column('account_id');
		$employees->field('name', 'string');
		$employees->belongsTo('account', 'accounts')
			->innerKey(['tenantId', 'accountId'])
			->outerKey(['tenantId', 'id'])
			->nullable(false)
			->end();
		$employees->relation('latestBadge', FirstOfManyRelation::class)
			->collection('employee_badges')
			->innerKey(['tenantId', 'name'])
			->outerKey(['tenantId', 'employeeName'])
			->orderBy(['label' => 'desc']);
		$employees->primaryKey('tenantId', 'name');

		$employeeBadges = $registry->collection('employee_badges');
		$employeeBadges->table('employee_badges');
		$employeeBadges->field('tenantId', 'int')->column('tenant_id');
		$employeeBadges->field('employeeName', 'string')->column('employee_name');
		$employeeBadges->field('badgeId', 'int')->column('badge_id');
		$employeeBadges->field('label', 'string');
		$employeeBadges->primaryKey('tenantId', 'employeeName', 'badgeId');

		$compositeArticles = $registry->collection('composite_articles');
		$compositeArticles->table('composite_articles');
		$compositeArticles->field('tenantId', 'int')->column('tenant_id');
		$compositeArticles->field('slug', 'string');
		$compositeArticles->field('title', 'string');
		$compositeArticles->primaryKey('tenantId', 'slug');
		$compositeArticles->relation('tags', M2MRelation::class)
			->collection('composite_tags')
			->innerKey(['tenantId', 'slug'])
			->outerKey(['tenantId', 'slug'])
			->nullable(true)
			->through('composite_article_tag')
				->innerKey(['article_tenant_id', 'article_slug'])
				->outerKey(['tag_tenant_id', 'tag_slug'])
				->end();

		$compositeTags = $registry->collection('composite_tags');
		$compositeTags->table('composite_tags');
		$compositeTags->field('tenantId', 'int')->column('tenant_id');
		$compositeTags->field('slug', 'string');
		$compositeTags->field('name', 'string');
		$compositeTags->primaryKey('tenantId', 'slug');

		$compositeArticleTag = $registry->collection('composite_article_tag');
		$compositeArticleTag->table('composite_article_tag');
		$compositeArticleTag->field('article_tenant_id', 'int');
		$compositeArticleTag->field('article_slug', 'string');
		$compositeArticleTag->field('tag_tenant_id', 'int');
		$compositeArticleTag->field('tag_slug', 'string');

		$departments = $registry->collection('departments');
		$departments->table('departments');
		$departments->field('id', 'int');
		$departments->field('companyId', 'int')->column('company_id');
		$departments->field('name', 'string');
		$departments->primaryKey('id');

		return $registry;
	}

	private function seedDatabase(): void
	{
		$pdo = new PDO($this->dsn);
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

		$pdo->exec('CREATE TABLE companies (id INTEGER PRIMARY KEY, name TEXT, active INTEGER)');
		$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, company_id INTEGER NULL)');
		$pdo->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY, user_id INTEGER, title TEXT, published INTEGER)');
		$pdo->exec('CREATE TABLE first_posts (id INTEGER PRIMARY KEY, user_id INTEGER, author_id INTEGER, title TEXT, created_at TEXT, rank INTEGER)');
		$pdo->exec('CREATE TABLE comments (id INTEGER PRIMARY KEY, post_id INTEGER, author_id INTEGER, body TEXT)');
		$pdo->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY, name TEXT)');
		$pdo->exec('CREATE TABLE user_roles (user_id INTEGER, role_id INTEGER)');
		$pdo->exec('CREATE TABLE permissions (id INTEGER PRIMARY KEY, role_id INTEGER, name TEXT)');
		$pdo->exec('CREATE TABLE profiles (id INTEGER PRIMARY KEY, user_id INTEGER, bio TEXT)');
		$pdo->exec('CREATE TABLE addresses (id INTEGER PRIMARY KEY, city TEXT)');
		$pdo->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY, billing_address_id INTEGER, shipping_address_id INTEGER)');
		$pdo->exec('CREATE TABLE articles (id INTEGER PRIMARY KEY, title TEXT)');
		$pdo->exec('CREATE TABLE tags (id INTEGER PRIMARY KEY, name TEXT)');
		$pdo->exec('CREATE TABLE article_tag (article_id INTEGER, tag_id INTEGER)');
		$pdo->exec('CREATE TABLE accounts (tenant_id INTEGER, id INTEGER, name TEXT, PRIMARY KEY (tenant_id, id))');
		$pdo->exec('CREATE TABLE employees (tenant_id INTEGER, account_id INTEGER, name TEXT, PRIMARY KEY (tenant_id, name))');
		$pdo->exec('CREATE TABLE employee_badges (tenant_id INTEGER, employee_name TEXT, badge_id INTEGER, label TEXT, PRIMARY KEY (tenant_id, employee_name, badge_id))');
		$pdo->exec('CREATE TABLE composite_articles (tenant_id INTEGER, slug TEXT, title TEXT, PRIMARY KEY (tenant_id, slug))');
		$pdo->exec('CREATE TABLE composite_tags (tenant_id INTEGER, slug TEXT, name TEXT, PRIMARY KEY (tenant_id, slug))');
		$pdo->exec('CREATE TABLE composite_article_tag (article_tenant_id INTEGER, article_slug TEXT, tag_tenant_id INTEGER, tag_slug TEXT)');
		$pdo->exec('CREATE TABLE departments (id INTEGER PRIMARY KEY, company_id INTEGER, name TEXT)');

		$pdo->exec("INSERT INTO companies (id, name, active) VALUES (1, 'Acme', 1), (2, 'Dormant', 0)");
		$pdo->exec("INSERT INTO users (id, name, company_id) VALUES (1, 'Ada', 1), (2, 'Grace', 1), (3, 'Linus', NULL)");
		$pdo->exec("INSERT INTO posts (id, user_id, title, published) VALUES (1, 1, 'Hello', 1), (2, 1, 'World', 0), (3, 2, 'Graph', 1)");
		$pdo->exec("INSERT INTO first_posts (id, user_id, author_id, title, created_at, rank) VALUES (10, 1, 1, 'Zulu', '2026-06-24 10:00:00', 2), (12, 1, 1, 'Alpha', '2026-06-24 11:00:00', 1), (11, 1, 2, 'Alpha', '2026-06-24 12:00:00', 1), (20, 2, 2, 'Beta', '2026-06-24 13:00:00', 1)");
		$pdo->exec("INSERT INTO comments (id, post_id, author_id, body) VALUES (1, 1, 1, 'First'), (2, 2, 2, 'Second'), (3, 3, 1, 'Third')");
		$pdo->exec("INSERT INTO roles (id, name) VALUES (10, 'Admin'), (11, 'Editor')");
		$pdo->exec('INSERT INTO user_roles (user_id, role_id) VALUES (1, 10), (1, 11), (2, 10)');
		$pdo->exec("INSERT INTO permissions (id, role_id, name) VALUES (100, 10, 'manage_users'), (101, 10, 'deploy'), (102, 11, 'edit_content')");
		$pdo->exec("INSERT INTO profiles (id, user_id, bio) VALUES (1, 1, 'Mathematician'), (2, 2, 'Compiler pioneer')");
		$pdo->exec("INSERT INTO addresses (id, city) VALUES (1, 'Sao Paulo'), (2, 'Rio')");
		$pdo->exec("INSERT INTO orders (id, billing_address_id, shipping_address_id) VALUES (1, 1, 2), (2, 2, 1)");
		$pdo->exec("INSERT INTO articles (id, title) VALUES (1, 'Joins'), (2, 'SQLite')");
		$pdo->exec("INSERT INTO tags (id, name) VALUES (1, 'php'), (2, 'orm'), (3, 'sqlite')");
		$pdo->exec('INSERT INTO article_tag (article_id, tag_id) VALUES (1, 1), (1, 2), (2, 3)');
		$pdo->exec("INSERT INTO accounts (tenant_id, id, name) VALUES (1, 10, 'Platform'), (2, 20, 'Infra')");
		$pdo->exec("INSERT INTO employees (tenant_id, account_id, name) VALUES (1, 10, 'Ada'), (1, 10, 'Grace'), (2, 20, 'Linus')");
		$pdo->exec("INSERT INTO employee_badges (tenant_id, employee_name, badge_id, label) VALUES (1, 'Ada', 2, 'Core'), (1, 'Ada', 1, 'Core'), (1, 'Ada', 3, 'Archive'), (1, 'Grace', 1, 'Compiler'), (2, 'Linus', 1, 'Kernel')");
		$pdo->exec("INSERT INTO composite_articles (tenant_id, slug, title) VALUES (1, 'joins', 'Joins'), (1, 'lonely', 'Lonely')");
		$pdo->exec("INSERT INTO composite_tags (tenant_id, slug, name) VALUES (1, 'php', 'php'), (1, 'orm', 'orm')");
		$pdo->exec("INSERT INTO composite_article_tag (article_tenant_id, article_slug, tag_tenant_id, tag_slug) VALUES (1, 'joins', 1, 'php'), (1, 'joins', 1, 'orm')");
		$pdo->exec("INSERT INTO departments (id, company_id, name) VALUES (1, 1, 'Engineering'), (2, 2, 'Archive')");
	}

	private function executorFromDatabase(Database $database): QueryExecutorInterface
	{
		$reflection = new ReflectionClass($database);
		$property = $reflection->getProperty('executor');

		return $property->getValue($database);
	}

	private function compileSqlWithExecutor(QueryExecutorInterface $executor, SelectQuery $query): string
	{
		$reflection = new ReflectionClass($executor);
		$property = $reflection->getProperty('translator');
		$translator = $property->getValue($executor);
		$translated = $translator->translate($query);
		$parameters = new QueryParameters();

		return $translated->query()->sqlStatement($parameters);
	}
}

final class RecordingQueryExecutor implements QueryExecutorInterface
{
	/**
	 * @var list<string>
	 */
	private array $derivedSql = [];

	/**
	 * @param callable(SelectQuery): string $compileSql
	 */
	public function __construct(
		private readonly QueryExecutorInterface $inner,
		private readonly Closure $compileSql,
	) {
	}

	public function fetchAll(SelectQuery $query): array
	{
		if ($query->getFrom() instanceof DerivedQuerySource) {
			$this->derivedSql[] = ($this->compileSql)($query);
		}

		return $this->inner->fetchAll($query);
	}

	public function fetchOne(SelectQuery $query): ?array
	{
		return $this->inner->fetchOne($query);
	}

	public function iterate(SelectQuery $query): iterable
	{
		return $this->inner->iterate($query);
	}

	/**
	 * @return list<string>
	 */
	public function derivedSql(): array
	{
		return $this->derivedSql;
	}
}

final class FirstOfManyFallbackExecutor implements QueryExecutorInterface
{
	public function fetchAll(SelectQuery $query): array
	{
		if ($query->getFrom() instanceof DerivedQuerySource) {
			throw UnsupportedQueryException::forQuery(
				$query,
				'FirstOfMany windowed loading requires derived-source and window-expression support from the query executor',
			);
		}

		return match ($query->getCollection()->getName()) {
			'users' => [
				['id' => 1, '__on_data_root_required_id_0' => 1, 'name' => 'Ada'],
				['id' => 2, '__on_data_root_required_id_0' => 2, 'name' => 'Grace'],
				['id' => 3, '__on_data_root_required_id_0' => 3, 'name' => 'Linus'],
			],
			'first_posts' => [
				['id' => 11, 'userId' => 1, 'title' => 'Alpha'],
				['id' => 12, 'userId' => 1, 'title' => 'Alpha'],
				['id' => 10, 'userId' => 1, 'title' => 'Zulu'],
				['id' => 20, 'userId' => 2, 'title' => 'Beta'],
			],
			default => [],
		};
	}

	public function fetchOne(SelectQuery $query): ?array
	{
		return $this->fetchAll($query)[0] ?? null;
	}

	public function iterate(SelectQuery $query): iterable
	{
		return $this->fetchAll($query);
	}
}
