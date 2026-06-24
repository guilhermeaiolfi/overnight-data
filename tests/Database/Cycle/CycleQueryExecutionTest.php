<?php

declare(strict_types=1);

namespace Tests\ON\Data\Database\Cycle;

use DateTimeImmutable;
use ON\Data\Database\ConnectionConfig;
use ON\Data\Database\Cycle\CycleDatabaseFactory;
use ON\Data\Database\Database;
use ON\Data\Database\Exception\UnsupportedQueryException;
use ON\Data\Definition\Registry;
use function ON\Data\Query\query;
use ON\Data\Query\SelectQuery;
use function ON\Data\Query\x;
use PDO;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

#[RequiresPhpExtension('pdo_sqlite')]
final class CycleQueryExecutionTest extends TestCase
{
	private string $databasePath;

	private string $dsn;

	private ?Registry $registry = null;

	private ?Database $database = null;

	protected function setUp(): void
	{
		$this->databasePath = tempnam(sys_get_temp_dir(), 'ondata-cycle-');
		$this->dsn = 'sqlite:' . str_replace('\\', '/', $this->databasePath);
		$this->registry = $this->makeRegistry();

		$this->seedDatabase();

		$this->database = (new CycleDatabaseFactory())->create(
			ConnectionConfig::dsn('sqlite', $this->dsn),
		);
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

	public function testFactoryBindsExecutableQueriesAndMapsFieldsThroughCycle(): void
	{
		$users = $this->database->query($this->registry->getCollection('users'));

		self::assertTrue($users->isExecutable());

		$rows = $users
			->select(
				$users->id,
				$users->name,
				$users->email,
				$users->createdAt,
				$users->profile,
			)
			->where(x()->eq($users->active, true))
			->orderBy($users->id->asc())
			->fetchAll();

		self::assertCount(2, $rows);
		self::assertSame('ada@example.test', $rows[0]['email']);
		self::assertInstanceOf(DateTimeImmutable::class, $rows[0]['createdAt']);
		self::assertSame(['role' => 'admin'], $rows[0]['profile']);
	}

	public function testFetchOneDoesNotMutateOriginalQueryState(): void
	{
		$users = $this->database->query($this->registry->getCollection('users'));
		$users
			->select($users->id, $users->name)
			->orderBy($users->id->desc())
			->offset(1);

		$row = $users->fetchOne();

		self::assertSame(2, $row['id']);
		self::assertNull($users->getLimit());
		self::assertSame(1, $users->getOffset());
	}

	public function testIterateYieldsMappedRowsLazily(): void
	{
		$users = $this->database->query($this->registry->getCollection('users'));

		$rows = iterator_to_array(
			$users
				->select($users->id, $users->createdAt)
				->orderBy($users->id->asc())
				->iterate(),
			false,
		);

		self::assertCount(3, $rows);
		self::assertInstanceOf(DateTimeImmutable::class, $rows[2]['createdAt']);
	}

	public function testAggregatesGroupingHavingSortingAndPaginationExecute(): void
	{
		$posts = $this->database->query($this->registry->getCollection('posts'));

		$rows = $posts
			->select(
				$posts->userId,
				$posts->amount->sum()->as('total'),
			)
			->groupBy($posts->userId)
			->having(x()->gt($posts->amount->sum(), 10))
			->orderBy($posts->amount->sum()->desc())
			->limit(1)
			->fetchAll();

		self::assertSame([[
			'userId' => 1,
			'total' => 25.5,
		]], $rows);
	}

	public function testExistsAndScalarSubqueriesExecute(): void
	{
		$usersDefinition = $this->registry->getCollection('users');
		$postsDefinition = $this->registry->getCollection('posts');
		$users = $this->database->query($usersDefinition);
		$posts = query($postsDefinition, function (SelectQuery $query) use ($users): void {
			$query->where(
				x()->eq($query->userId, $users->id),
				x()->eq($query->published, true),
			);
		});

		$rows = $users
			->select(
				$users->name,
				query($postsDefinition, function (SelectQuery $query) use ($users): void {
					$query
						->select($query->id->count())
						->where(x()->eq($query->userId, $users->id));
				})->as('post_count'),
			)
			->where(x()->exists($posts))
			->orderBy($users->id->asc())
			->fetchAll();

		self::assertSame([
			['name' => 'Ada', 'post_count' => 2],
			['name' => 'Grace', 'post_count' => 1],
		], $rows);
	}

	public function testRootExecutionRequiresSelections(): void
	{
		$this->expectException(UnsupportedQueryException::class);
		$this->expectExceptionMessage('root execution requires at least one selection');

		$this->database->query($this->registry->getCollection('users'))->fetchAll();
	}

	public function testUnaliasedComputedRootSelectionsAreRejected(): void
	{
		$users = $this->database->query($this->registry->getCollection('users'));

		$this->expectException(UnsupportedQueryException::class);
		$this->expectExceptionMessage('unaliased computed, aggregate, and subquery root selections are not supported');

		$users->select($users->name->upper())->fetchAll();
	}

	public function testDuplicateRootResultNamesAreRejected(): void
	{
		$users = $this->database->query($this->registry->getCollection('users'));

		$this->expectException(UnsupportedQueryException::class);
		$this->expectExceptionMessage("duplicate root result name 'id'");

		$users
			->select($users->id, $users->name->as('id'))
			->fetchAll();
	}

	public function testInvalidFieldScopeIsRejected(): void
	{
		$definition = $this->registry->getCollection('users');
		$users = $this->database->query($definition);
		$other = query($definition);

		$this->expectException(UnsupportedQueryException::class);
		$this->expectExceptionMessage('outside the active query scope');

		$users
			->select($users->id)
			->where(x()->eq($users->id, $other->id))
			->fetchAll();
	}

	public function testScalarSubqueriesRequireExactlyOneSelection(): void
	{
		$users = $this->database->query($this->registry->getCollection('users'));
		$posts = query($this->registry->getCollection('posts'), function (SelectQuery $query): void {
			$query->select($query->id, $query->title);
		});

		$this->expectException(UnsupportedQueryException::class);
		$this->expectExceptionMessage('scalar subqueries require exactly one selection');

		$users
			->select($users->id)
			->where(x()->eq($users->id, $posts))
			->fetchAll();
	}

	private function makeRegistry(): Registry
	{
		$registry = new Registry();

		$users = $registry->collection('users');
		$users->table('users');
		$users->field('id', 'int');
		$users->field('name', 'string');
		$users->field('email', 'string')->column('mail_address');
		$users->field('active', 'bool');
		$users->field('createdAt', 'datetime')->column('created_at');
		$users->field('profile', 'json')->column('profile_json')->nullable(true);
		$users->primaryKey('id');

		$posts = $registry->collection('posts');
		$posts->table('posts');
		$posts->field('id', 'int');
		$posts->field('userId', 'int')->column('user_id');
		$posts->field('title', 'string');
		$posts->field('amount', 'float');
		$posts->field('published', 'bool');
		$posts->primaryKey('id');

		return $registry;
	}

	private function seedDatabase(): void
	{
		$pdo = new PDO($this->dsn);
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

		$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, mail_address TEXT, active INTEGER, created_at TEXT, profile_json TEXT)');
		$pdo->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY, user_id INTEGER, title TEXT, amount REAL, published INTEGER)');

		$users = $pdo->prepare('INSERT INTO users (id, name, mail_address, active, created_at, profile_json) VALUES (?, ?, ?, ?, ?, ?)');
		$users->execute([1, 'Ada', 'ada@example.test', 1, '2026-06-24 10:00:00', '{"role":"admin"}']);
		$users->execute([2, 'Grace', 'grace@example.test', 1, '2026-06-24 11:00:00', '{"role":"editor"}']);
		$users->execute([3, 'Linus', 'linus@example.test', 0, '2026-06-24 12:00:00', null]);

		$posts = $pdo->prepare('INSERT INTO posts (id, user_id, title, amount, published) VALUES (?, ?, ?, ?, ?)');
		$posts->execute([1, 1, 'Hello', 10.0, 1]);
		$posts->execute([2, 1, 'World', 15.5, 0]);
		$posts->execute([3, 2, 'Graph', 7.5, 1]);
	}
}
