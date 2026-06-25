<?php

declare(strict_types=1);

namespace Tests\ON\Data\Database\Cycle;

use InvalidArgumentException;
use ON\Data\Database\ConnectionConfig;
use ON\Data\Database\Database;
use ON\Data\Database\Exception\UnsupportedQueryException;
use ON\Data\Definition\Registry;
use ON\Data\Definition\Relation\FirstOfManyRelation;
use ON\Data\Definition\Relation\M2MRelation;
use ON\Data\Query\Join;
use ON\Data\Query\JoinType;
use ON\Data\Query\Relation\LoadRuntime;
use ON\Data\Query\SelectQuery;
use function ON\Data\Query\x;
use PDO;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

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
				\ON\Data\Query\query($posts, function (SelectQuery $query) use ($users): void {
					$query
						->select($query->author->name->upper())
						->where(x()->eq($query->userId, $users->id))
						->orderBy($query->id->asc())
						->limit(1);
				})->as('first_author'),
			)
			->orderBy($users->id->asc())
			->fetchAll();

		self::assertSame([
			['name' => 'Ada', 'first_author' => 'ADA'],
			['name' => 'Grace', 'first_author' => 'GRACE'],
			['name' => 'Linus', 'first_author' => null],
		], $rows);
	}

	public function testFirstOfManyJoinFailsExplicitly(): void
	{
		$users = $this->database->query($this->registry->getCollection('users'));

		$this->expectException(UnsupportedQueryException::class);
		$this->expectExceptionMessage('FirstOfMany join semantics');

		$users
			->select($users->latestPost->title)
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

	public function testLoadBoundaryRemainsReserved(): void
	{
		$users = $this->database->query($this->registry->getCollection('users'));

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Relation loading is not implemented for relation "posts".');
		$users->posts->getLoader()->load($users->posts, new LoadRuntime());
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
		$users->relation('latestPost', FirstOfManyRelation::class)
			->collection('posts')
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
		$posts->primaryKey('id');

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
		$employees->primaryKey('tenantId', 'name');

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
		$pdo->exec('CREATE TABLE profiles (id INTEGER PRIMARY KEY, user_id INTEGER, bio TEXT)');
		$pdo->exec('CREATE TABLE addresses (id INTEGER PRIMARY KEY, city TEXT)');
		$pdo->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY, billing_address_id INTEGER, shipping_address_id INTEGER)');
		$pdo->exec('CREATE TABLE articles (id INTEGER PRIMARY KEY, title TEXT)');
		$pdo->exec('CREATE TABLE tags (id INTEGER PRIMARY KEY, name TEXT)');
		$pdo->exec('CREATE TABLE article_tag (article_id INTEGER, tag_id INTEGER)');
		$pdo->exec('CREATE TABLE accounts (tenant_id INTEGER, id INTEGER, name TEXT, PRIMARY KEY (tenant_id, id))');
		$pdo->exec('CREATE TABLE employees (tenant_id INTEGER, account_id INTEGER, name TEXT, PRIMARY KEY (tenant_id, name))');
		$pdo->exec('CREATE TABLE composite_articles (tenant_id INTEGER, slug TEXT, title TEXT, PRIMARY KEY (tenant_id, slug))');
		$pdo->exec('CREATE TABLE composite_tags (tenant_id INTEGER, slug TEXT, name TEXT, PRIMARY KEY (tenant_id, slug))');
		$pdo->exec('CREATE TABLE composite_article_tag (article_tenant_id INTEGER, article_slug TEXT, tag_tenant_id INTEGER, tag_slug TEXT)');
		$pdo->exec('CREATE TABLE departments (id INTEGER PRIMARY KEY, company_id INTEGER, name TEXT)');

		$pdo->exec("INSERT INTO companies (id, name, active) VALUES (1, 'Acme', 1), (2, 'Dormant', 0)");
		$pdo->exec("INSERT INTO users (id, name, company_id) VALUES (1, 'Ada', 1), (2, 'Grace', 1), (3, 'Linus', NULL)");
		$pdo->exec("INSERT INTO posts (id, user_id, title, published) VALUES (1, 1, 'Hello', 1), (2, 1, 'World', 0), (3, 2, 'Graph', 1)");
		$pdo->exec("INSERT INTO profiles (id, user_id, bio) VALUES (1, 1, 'Mathematician'), (2, 2, 'Compiler pioneer')");
		$pdo->exec("INSERT INTO addresses (id, city) VALUES (1, 'Sao Paulo'), (2, 'Rio')");
		$pdo->exec("INSERT INTO orders (id, billing_address_id, shipping_address_id) VALUES (1, 1, 2), (2, 2, 1)");
		$pdo->exec("INSERT INTO articles (id, title) VALUES (1, 'Joins'), (2, 'SQLite')");
		$pdo->exec("INSERT INTO tags (id, name) VALUES (1, 'php'), (2, 'orm'), (3, 'sqlite')");
		$pdo->exec('INSERT INTO article_tag (article_id, tag_id) VALUES (1, 1), (1, 2), (2, 3)');
		$pdo->exec("INSERT INTO accounts (tenant_id, id, name) VALUES (1, 10, 'Platform'), (2, 20, 'Infra')");
		$pdo->exec("INSERT INTO employees (tenant_id, account_id, name) VALUES (1, 10, 'Ada'), (1, 10, 'Grace'), (2, 20, 'Linus')");
		$pdo->exec("INSERT INTO composite_articles (tenant_id, slug, title) VALUES (1, 'joins', 'Joins'), (1, 'lonely', 'Lonely')");
		$pdo->exec("INSERT INTO composite_tags (tenant_id, slug, name) VALUES (1, 'php', 'php'), (1, 'orm', 'orm')");
		$pdo->exec("INSERT INTO composite_article_tag (article_tenant_id, article_slug, tag_tenant_id, tag_slug) VALUES (1, 'joins', 1, 'php'), (1, 'joins', 1, 'orm')");
		$pdo->exec("INSERT INTO departments (id, company_id, name) VALUES (1, 1, 'Engineering'), (2, 2, 'Archive')");
	}
}
